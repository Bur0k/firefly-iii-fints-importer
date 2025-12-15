<?php
namespace App\StepFunction;

use App\FinTsFactory;
use App\Logger;
use App\Step;
use App\TanHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;

function Login()
{
    global $request, $session, $twig, $fin_ts, $automate_without_js;

    if ($request->request->has('bank_2fa_device')) {
        $session->set('bank_2fa_device', $request->request->get('bank_2fa_device'));
    }
    $fin_ts = FinTsFactory::create_from_session($session);

    $current_step  = new Step($request->request->get("step", Step::STEP0_SETUP));
    $login_handler = new TanHandler(
        function () {
            global $fin_ts;
            // fresh start, forget any dialog that may have been persisted
            $fin_ts->forgetDialog();
            return $fin_ts->login();
        },
        'login-action',
        $session,
        $twig,
        $fin_ts,
        $current_step,
        $request
    );

    if ($login_handler->needs_tan()) {
        $login_handler->pose_and_render_tan_challenge();
    } else {
        // Detect supported statement formats from BPD (now safely cached after login)
        $bpd = $fin_ts->getBpd();
        $supports_camt = $bpd->getLatestSupportedParameters('HICAZS') !== null;
        $supports_mt940 = $bpd->getLatestSupportedParameters('HIKAZS') !== null;

        if ($supports_camt) {
            $session->set('statement_format', 'camt');
            Logger::info("Bank supports CAMT XML format (HICAZS)");
        } elseif ($supports_mt940) {
            $session->set('statement_format', 'mt940');
            Logger::info("Bank supports MT940 format (HIKAZS)");
        } else {
            Logger::warning("Bank supports neither CAMT nor MT940 - will attempt CAMT first");
            $session->set('statement_format', 'camt'); // Default, let exception handling deal with it
        }

        if ($automate_without_js)
        {
            $session->set('persistedFints', $fin_ts->persist());
            return Step::STEP3_CHOOSE_ACCOUNT;
        }
        echo $twig->render(
            'skip-form.twig',
            array(
                'next_step' => Step::STEP3_CHOOSE_ACCOUNT,
                'message' => "The connection to your bank was tested sucessfully."
            )
        );
    }
    $session->set('persistedFints', $fin_ts->persist());
    return Step::DONE;
}