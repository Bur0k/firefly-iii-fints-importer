<?php
namespace App\StepFunction;

use App\FinTsFactory;
use App\Logger;
use App\Step;
use App\TanHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;

function GetImportData()
{
    global $request, $session, $twig, $fin_ts, $accounts, $automate_without_js;

    $fin_ts = FinTsFactory::create_from_session($session);

    $accounts = unserialize($session->get('accounts'));
    $current_step  = new Step($request->request->get("step", Step::STEP0_SETUP));
    $soa_handler = new TanHandler(
        function () {
            global $fin_ts, $request, $accounts, $session;
            assert($request->request->has('bank_account'));
            assert($request->request->has('firefly_account'));
            assert($request->request->has('date_from'));
            assert($request->request->has('date_to'));
            $bank_account = $accounts[intval($request->request->get('bank_account'))];
            $from         = new \DateTime($request->request->get('date_from'));
            $to           = new \DateTime($request->request->get('date_to'));
            $session->set('firefly_account', $request->request->get('firefly_account'));
            $get_statement = \Fhp\Action\GetStatementOfAccountXML::create($bank_account, $from, $to);
            $fin_ts->execute($get_statement);
            return $get_statement;
        },
        'soa',
        $session,
        $twig,
        $fin_ts,
        $current_step,
        $request
    );
    if ($soa_handler->needs_tan()) {
        $soa_handler->pose_and_render_tan_challenge();
    } else {
        $next_step = Step::STEP5_RUN_IMPORT_BATCHED;

        // Get CAMT XML from the action and parse it
        $camt_xml_array = $soa_handler->get_finished_action()->getBookedXML();

        Logger::trace("CAMT XML array count: " . count($camt_xml_array));
        if (!empty($camt_xml_array) && isset($camt_xml_array[0])) {
            Logger::trace("First CAMT XML length: " . strlen($camt_xml_array[0]));
        }

        // GetStatementOfAccountXML returns an array of XML strings, we process the first one
        $camt_xml = $camt_xml_array[0] ?? '';

        if (empty(trim($camt_xml))) {
            $date_from = $request->request->get('date_from', 'unknown');
            $date_to = $request->request->get('date_to', 'unknown');
            Logger::warn("No CAMT XML data returned from bank. " .
                         "Date range: " . $date_from . " to " . $date_to);
            // Return empty transactions - user will see "no transactions" instead of crash
            $transactions = [];
        } else {
            $transactions = \App\StatementOfAccountHelper::parse_camt_xml($camt_xml);
        }
        $session->set('transactions_to_import', serialize($transactions));
        $session->set('num_transactions_processed', 0);
        $session->set('import_messages', serialize(array()));

        if ($automate_without_js)
        {
            $session->set('persistedFints', $fin_ts->persist());
            return $next_step;
        }
        
        echo $twig->render(
            'show-transactions.twig',
            array(
                'transactions' => $transactions,
                'next_step' => $next_step,
                'skip_transaction_review' => $session->get('skip_transaction_review')
            )
        );
    }
    $fin_ts->close();
    $session->set('persistedFints', $fin_ts->persist());
    return Step::DONE;
}
