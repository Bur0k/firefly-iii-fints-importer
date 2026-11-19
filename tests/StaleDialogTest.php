<?php

use App\Step;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

use function App\StepFunction\handle_stale_dialog;

require_once __DIR__ . '/../app/StaleDialog.php';

final class StaleDialogTest extends TestCase
{
    protected function setUp(): void
    {
        global $automate_without_js, $session;
        $automate_without_js = true;
        $session = new Session(new MockArraySessionStorage());
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['automate_without_js'], $GLOBALS['session']);
    }

    public function test_automated_recovery_stops_after_one_relogin(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('error.twig', $this->callback(function (array $context): bool {
                return $context['error_header'] === 'Bank session recovery failed'
                    && strpos($context['error_message'], 'one automatic re-login') !== false;
            }))
            ->willReturn('Recovery stopped');
        $this->expectOutputString('Recovery stopped');

        $this->assertSame(Step::STEP2_LOGIN, handle_stale_dialog($twig));
        $this->assertSame(Step::DONE, handle_stale_dialog($twig));
    }

    public function test_exhausted_session_does_not_get_another_automatic_retry(): void
    {
        global $session;
        $session->set('stale_dialog_relogin_attempts', 1);
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('error.twig', $this->anything())
            ->willReturn('Recovery stopped');
        $this->expectOutputString('Recovery stopped');

        $this->assertSame(Step::DONE, handle_stale_dialog($twig));
    }

    public function test_interactive_relogin_remains_available_after_automatic_retry(): void
    {
        global $automate_without_js, $session;
        $automate_without_js = false;
        $session->set('stale_dialog_relogin_attempts', 1);
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('relogin.twig', $this->callback(function (array $context): bool {
                return $context['next_step'] === Step::STEP2_LOGIN;
            }))
            ->willReturn('Please log in again');
        $this->expectOutputString('Please log in again');

        $this->assertSame(Step::DONE, handle_stale_dialog($twig));
    }
}
