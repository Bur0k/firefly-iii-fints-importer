<?php

namespace App;

use Fhp\Model\StatementOfAccount\Transaction;
use Genkgo\Camt\Config;
use Genkgo\Camt\Reader;

class StatementOfAccountHelper
{
    /** @return Transaction[] */
    public static function get_all_transactions(\Fhp\Model\StatementOfAccount\StatementOfAccount $soa){
        $transactions = array();
        foreach($soa->getStatements() as $statement){
            $transactions = array_merge($transactions, $statement->getTransactions());
        }
        return $transactions;
    }

    /**
     * Parse CAMT XML and convert to Transaction objects
     * @param string $xml CAMT XML string from GetStatementOfAccountXML
     * @return Transaction[]
     */
    public static function parse_camt_xml(string $xml): array {
        try {
            // Initialize CAMT reader with default configuration
            $reader = new Reader(Config::getDefault());
            $message = $reader->readString($xml);

            $transactions = [];

            // Iterate through records (statements) and entries (transactions)
            foreach ($message->getRecords() as $record) {
                foreach ($record->getEntries() as $entry) {
                    // Create a new Transaction object
                    $transaction = new Transaction();

                    // Set credit/debit indicator
                    $cdIndicator = $entry->getCreditDebitIndicator();
                    if ($cdIndicator === 'CRDT') {
                        $transaction->setCreditDebit(Transaction::CD_CREDIT);
                    } else {
                        $transaction->setCreditDebit(Transaction::CD_DEBIT);
                    }

                    // Set valuta date (convert DateTimeImmutable to DateTime)
                    $valutaDate = $entry->getValueDate();
                    if ($valutaDate) {
                        $transaction->setValutaDate(\DateTime::createFromImmutable($valutaDate));
                    }

                    // Set booking date as fallback
                    $bookingDate = $entry->getBookingDate();
                    if ($bookingDate && !$valutaDate) {
                        $transaction->setValutaDate(\DateTime::createFromImmutable($bookingDate));
                    }

                    // Set amount (convert Money object to float)
                    $amount = $entry->getAmount();
                    $transaction->setAmount((float)($amount->getAmount() / (10 ** $amount->getCurrency()->getDefaultFractionDigits())));

                    // Get transaction details (first detail if multiple exist)
                    $detail = $entry->getTransactionDetail();

                    if ($detail) {
                        // Set counterparty account number (IBAN)
                        $relatedParty = $detail->getRelatedParty();
                        if ($relatedParty && $relatedParty->getAccount()) {
                            $transaction->setAccountNumber($relatedParty->getAccount()->getIdentification());
                        }

                        // Set counterparty name
                        if ($relatedParty && $relatedParty->getRelatedPartyType()) {
                            $partyType = $relatedParty->getRelatedPartyType();
                            $name = $partyType->getName();
                            if ($name) {
                                $transaction->setName($name);
                            }
                        }

                        // Set remittance information and end-to-end ID via structured description
                        $structuredDesc = [];

                        $remittanceInfo = $detail->getRemittanceInformation();
                        if ($remittanceInfo) {
                            $message = $remittanceInfo->getMessage();
                            if ($message) {
                                $structuredDesc['SVWZ'] = $message;
                            }
                        }

                        // Set end-to-end ID (SEPA reference) - stored in EREF key
                        $reference = $detail->getReference();
                        if ($reference) {
                            $endToEndId = $reference->getEndToEndId();
                            if ($endToEndId) {
                                $structuredDesc['EREF'] = $endToEndId;
                            }
                        }

                        // Set ABWA field (used for notes in Firefly III) - use counterparty name as fallback
                        if ($relatedParty && $relatedParty->getRelatedPartyType()) {
                            $partyName = $relatedParty->getRelatedPartyType()->getName();
                            if ($partyName) {
                                $structuredDesc['ABWA'] = $partyName;
                            }
                        }

                        // Set structured description (used by getMainDescription and getEndToEndID)
                        if (!empty($structuredDesc)) {
                            $transaction->setStructuredDescription($structuredDesc);
                        }
                    }

                    // Set booking text from entry-level additional info
                    $additionalInfo = $entry->getAdditionalInfo();
                    if ($additionalInfo) {
                        $transaction->setBookingText($additionalInfo);
                    }

                    // Set description1 as additional fallback (use counterparty name or additional info)
                    $description1 = '';
                    if ($detail && $detail->getRemittanceInformation()) {
                        // Try to get unstructured remittance info blocks as fallback
                        $unstructuredBlocks = $detail->getRemittanceInformation()->getUnstructuredBlocks();
                        if (!empty($unstructuredBlocks)) {
                            $description1 = (string) $unstructuredBlocks[0];
                        }
                    }
                    if (empty($description1) && $additionalInfo) {
                        $description1 = $additionalInfo;
                    }
                    if (!empty($description1)) {
                        $transaction->setDescription1($description1);
                    }

                    $transactions[] = $transaction;
                }
            }

            return $transactions;

        } catch (\Exception $e) {
            // Log the error and return empty array to prevent fatal errors
            error_log("CAMT XML parsing failed: " . $e->getMessage());
            return [];
        }
    }
}