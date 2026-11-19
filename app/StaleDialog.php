<?php
namespace App\StepFunction;

use App\Logger;
use App\Step;
use Twig\Environment;

/**
 * The bank rejected the saved FinTS dialog. TanHandler has dropped the stale state.
 * This renders
 * a page that sends the user back to the login step (or, in automated mode, continues
 * the flow to the login step directly) and returns the step that index.php's do-while
 * loop should proceed to next.
 */
function handle_stale_dialog(Environment $twig): string
{
    global $automate_without_js, $session;
    if ($automate_without_js) {
        // Keep the budget across successful logins; only a fresh import session resets it.
        if ($session->get('stale_dialog_relogin_attempts', 0) >= 1) {
            $message = 'The bank dialog was rejected again after one automatic re-login. '
                . 'The import has stopped to prevent repeated login attempts.';
            Logger::error($message);
            echo $twig->render(
                'error.twig',
                array(
                    'error_header' => 'Bank session recovery failed',
                    'error_message' => $message
                )
            );
            return Step::DONE;
        }
        $session->set('stale_dialog_relogin_attempts', 1);
        Logger::warn('Bank dialog is invalid; attempting one automatic re-login.');
        return Step::STEP2_LOGIN;
    }
    echo $twig->render(
        'relogin.twig',
        array(
            'next_step' => Step::STEP2_LOGIN,
            'message' => 'Your saved bank session is invalid or out of sync. '
                . 'Please log in again to continue.'
        )
    );
    return Step::DONE;
}