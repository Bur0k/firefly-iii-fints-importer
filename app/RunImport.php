<?php
namespace App\StepFunction;

use App\TransactionsToFireflySender;
use App\Step;
use Symfony\Component\HttpFoundation\Session\Session;

function RunImport()
{
    global $session, $twig;

    assert($session->has('transactions_to_import'));
    assert($session->has('firefly_account'));
    $transactions = unserialize($session->get('transactions_to_import'));

    if (empty($transactions)) {
        $import_messages = ['No transactions to import.'];
    } else {
        $sender = new TransactionsToFireflySender(
            $transactions,
            $session->get('firefly_url'),
            $session->get('firefly_access_token'),
            $session->get('firefly_account')
        );
        $result = $sender->send_transactions();
        if (is_array($result)) {
            $import_messages = $result;
        }

    }
    echo $twig->render(
        'done.twig',
        array(
            'import_messages' => $import_messages,
            'total_num_transactions' => count($transactions)
        )
    );
    $session->invalidate();
    return Step::DONE;
}
