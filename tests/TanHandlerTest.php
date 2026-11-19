<?php

use App\Step;
use App\TanHandler;
use Fhp\BaseAction;
use Fhp\FinTs;
use Fhp\Model\TanMode;
use Fhp\Protocol\Message;
use Fhp\Protocol\ServerException;
use Fhp\Segment\HIRMS\Rueckmeldung;
use Fhp\Segment\HIRMS\Rueckmeldungscode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

final class TanHandlerTest extends TestCase
{
    /**
     * @dataProvider recoverable_errors
     */
    public function test_dialog_errors_request_relogin_and_clear_stale_state(int $code, string $text): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('persistedFints', 'stale dialog');
        $session->set('accounts', 'stale accounts');
        $session->set('force_mt940', true);
        $session->set('stale_dialog_relogin_attempts', 1);
        $exception = $this->server_exception($code, $text);

        $handler = $this->create_handler($session, $exception);

        $this->assertTrue($handler->needs_relogin());
        $this->assertFalse($session->has('persistedFints'));
        $this->assertFalse($session->has('accounts'));
        $this->assertTrue($session->get('force_mt940'));
        $this->assertSame(1, $session->get('stale_dialog_relogin_attempts'));
    }

    public static function recoverable_errors(): array
    {
        return [
            'invalid dialog' => [9010, 'Invalid dialog ID'],
            'unexpected message number' => [9120, 'Die Nachricht hat nicht die erwartete Nachrichtennummer.'],
        ];
    }

    /**
     * @dataProvider non_recoverable_errors
     */
    public function test_other_errors_are_rethrown_without_discarding_state(
        int $code,
        string $text,
        ?int $reference
    ): void {
        $session = new Session(new MockArraySessionStorage());
        $session->set('persistedFints', 'saved dialog');
        $exception = $this->server_exception($code, $text, $reference);
        $this->expectExceptionObject($exception);

        try {
            $this->create_handler($session, $exception);
        } finally {
            $this->assertSame('saved dialog', $session->get('persistedFints'));
        }
    }

    public static function non_recoverable_errors(): array
    {
        return [
            'other global 9120' => [9120, 'Invalid request message', null],
            'segment-scoped 9120' => [9120, 'Die Nachricht hat nicht die erwartete Nachrichtennummer.', 4],
            'segment-scoped 9010' => [9010, 'Order rejected', 4],
            'other global error' => [9942, 'Authentication failed', null],
        ];
    }

    /**
     * @dataProvider recoverable_errors
     */
    public function test_fresh_login_errors_are_not_recovered(int $code, string $text): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('persistedFints', 'previous dialog');
        $exception = $this->server_exception($code, $text);
        $this->expectExceptionObject($exception);

        try {
            $this->create_handler($session, $exception, 'login-action');
        } finally {
            $this->assertSame('previous dialog', $session->get('persistedFints'));
        }
    }

    public function test_action_without_saved_dialog_is_not_recovered(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $exception = $this->server_exception(9010, 'Processing not possible');
        $this->expectExceptionObject($exception);

        $this->create_handler($session, $exception);
    }

    /**
     * @dataProvider authentication_errors
     */
    public function test_authentication_errors_override_global_dialog_errors(int $code, bool $warning): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('persistedFints', 'saved dialog');
        $authError = new Rueckmeldung();
        $authError->rueckmeldungscode = $code;
        $authError->rueckmeldungstext = 'Authentication failed';
        $authError->referenceSegment = 4;
        $dialogError = $this->server_exception(9010, 'Processing not possible');
        $exception = new ServerException(
            array_merge($dialogError->getErrors(), $warning ? [] : [$authError]),
            $warning ? [$authError] : [],
            [],
            new Message(),
            new Message()
        );
        $this->expectExceptionObject($exception);

        try {
            $this->create_handler($session, $exception);
        } finally {
            $this->assertSame('saved dialog', $session->get('persistedFints'));
        }
    }

    public static function authentication_errors(): array
    {
        return [
            'wrong PIN' => [Rueckmeldungscode::PIN_UNGUELTIG, false],
            'locked PIN' => [Rueckmeldungscode::PIN_GESPERRT, false],
            'wrong TAN' => [Rueckmeldungscode::TAN_UNGUELTIG, false],
            'temporary lock warning' => [Rueckmeldungscode::ZUGANG_VORLAEUFIG_GESPERRT, true],
        ];
    }

    public function test_resuming_login_can_still_recover_a_rejected_dialog(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('persistedFints', 'saved dialog');
        $session->set('login-action', serialize($this->createMock(BaseAction::class)));
        $exception = $this->server_exception(9010, 'Invalid dialog ID');
        $tanMode = $this->createMock(TanMode::class);
        $tanMode->method('isDecoupled')->willReturn(false);
        $finTs = $this->createMock(FinTs::class);
        $finTs->expects($this->once())->method('getSelectedTanMode')->willReturn($tanMode);
        $finTs->expects($this->once())->method('submitTan')->willThrowException($exception);

        $handler = $this->create_handler($session, $exception, 'login-action', $finTs);

        $this->assertTrue($handler->needs_relogin());
        $this->assertFalse($session->has('persistedFints'));
        $this->assertFalse($session->has('login-action'));
    }

    private function server_exception(int $code, string $text, ?int $reference = null): ServerException
    {
        $error = new Rueckmeldung();
        $error->rueckmeldungscode = $code;
        $error->rueckmeldungstext = $text;
        $error->referenceSegment = $reference;

        $segmentError = new Rueckmeldung();
        $segmentError->rueckmeldungscode = 9010;
        $segmentError->rueckmeldungstext = 'Order rejected';
        $segmentError->referenceSegment = 4;

        return new ServerException([$error, $segmentError], [], [], new Message(), new Message());
    }

    private function create_handler(
        Session $session,
        ServerException $exception,
        string $action_id = 'list-accounts',
        ?FinTs $finTs = null
    ): TanHandler {
        return new TanHandler(
            static function () use ($exception) {
                throw $exception;
            },
            $action_id,
            $session,
            $this->createMock(Environment::class),
            $finTs ?? $this->createMock(FinTs::class),
            new Step(Step::STEP3_CHOOSE_ACCOUNT),
            new Request([], ['tan' => '000000'])
        );
    }
}
