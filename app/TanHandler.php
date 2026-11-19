<?php


namespace App;


use Fhp\BaseAction;
use Fhp\FinTs;
use Fhp\Protocol\ServerException;
use Symfony\Component\HttpFoundation\Session\Session;
use Twig\Environment;

class TanHandler
{
    public function __construct(
        callable $create_action_lambda,
        string $action_id,
        Session $session,
        Environment $twig,
        FinTs $fin_ts,
        \App\Step $current_step,
        \Symfony\Component\HttpFoundation\Request $request
    )
    {
        $this->create_action_lambda = $create_action_lambda;
        $this->action_id            = $action_id;
        $this->session              = $session;
        $this->twig                 = $twig;
        $this->fin_ts               = $fin_ts;
        $this->current_step         = $current_step;
        $this->request              = $request;
        $this->create_or_continue_action();
    }

    private function create_or_continue_action(): void
    {
        $resuming_action = $this->session->has($this->action_id);
        try {
            if ($resuming_action) {
                $this->action = unserialize($this->session->get($this->action_id));
                $this->session->remove($this->action_id);
                if ($this->fin_ts->getSelectedTanMode()->isDecoupled()) {
                    if ($this->action->getTanRequest() != null && !$this->fin_ts->checkDecoupledSubmission($this->action)) {
                        $resuming_action = false;
                        $this->action = ($this->create_action_lambda)();
                    }
                } else {
                    $this->fin_ts->submitTan($this->action, $this->request->request->get('tan'));
                }
            } else {
                $this->action = ($this->create_action_lambda)();
            }
        } catch (ServerException $e) {
            // A fresh login forgets the previous dialog, even if its snapshot is still saved.
            $used_saved_dialog = $resuming_action
                || ($this->action_id !== 'login-action' && $this->session->has('persistedFints'));
            if (!$used_saved_dialog || !self::indicates_invalid_dialog($e)) {
                throw $e;
            }
            // Restart authentication rather than replaying an action on a rejected dialog.
            $this->needs_relogin = true;
            $this->session->remove('persistedFints');
            $this->session->remove('accounts');
            $this->session->remove($this->action_id);
        }
    }

    /**
     * Returns true if the saved dialog is invalid or its message counter is out of sync.
     */
    public function needs_relogin(): bool
    {
        return $this->needs_relogin;
    }

    /**
     * Only global dialog errors qualify; 9120 can also report unrelated request errors.
     */
    private static function indicates_invalid_dialog(ServerException $e): bool
    {
        if ($e->indicatesBadLoginData() || $e->indicatesLocked() || $e->indicatesBadTan()) {
            return false;
        }
        foreach ($e->getErrors() as $error) {
            if ($error->referenceSegment !== null) {
                continue;
            }
            if ($error->rueckmeldungscode === 9010) {
                return true;
            }
            if ($error->rueckmeldungscode === 9120
                && stripos($error->rueckmeldungstext, 'nicht die erwartete Nachrichtennummer') !== false) {
                return true;
            }
        }
        return false;
    }

    public function needs_tan(): bool
    {
        return $this->action->needsTan();
    }

    public function pose_and_render_tan_challenge(): void
    {
        assert($this->needs_tan());
        $tanRequest = $this->action->getTanRequest();
        if ($tanRequest->getChallengeHhdUc()) {
            try {
                $challengeImage    = new \Fhp\Model\TanRequestChallengeImage(
                    $tanRequest->getChallengeHhdUc()
                );
                $challengeImageSrc =
                    'data:' . htmlspecialchars($challengeImage->getMimeType()) .
                    ';base64,' . base64_encode($challengeImage->getData());
            } catch (\RuntimeException $e) {
                $challengeImageSrc = null;
            }
        }else{
            $challengeImageSrc = null;
        }
        echo $this->twig->render(
            'tan-challenge.twig',
            array(
                'next_step' => $this->current_step,
                'challenge' => $tanRequest->getChallenge(),
                'device' => $tanRequest->getTanMediumName(),
                'challenge_image_src' => $challengeImageSrc,
                'is_decoupled_tan_mode' => $this->fin_ts->getSelectedTanMode()->isDecoupled(),
            )
        );
        $this->session->set($this->action_id, serialize($this->action));
    }

    public function get_finished_action(): BaseAction
    {
        return $this->action;
    }

    private $action;
    private $needs_relogin = false;
    private $create_action_lambda;
    private $action_id;
    private $session;
    private $twig;
    private $fin_ts;
    private $current_step;
    private $request;
}