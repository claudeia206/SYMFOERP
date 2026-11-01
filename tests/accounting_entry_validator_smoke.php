<?php

declare(strict_types=1);

use App\Service\AccountingEntryValidator;

require dirname(__DIR__).'/vendor/autoload.php';

function expectAccountingEntry(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$valid = [
    ['account' => '411000', 'label' => 'Client', 'debit' => 12345, 'credit' => 0],
    ['account' => '707000', 'label' => 'Ventes', 'debit' => 0, 'credit' => 10000],
    ['account' => '445710', 'label' => 'TVA collectée', 'debit' => 0, 'credit' => 2345],
];
expectAccountingEntry(
    AccountingEntryValidator::validate($valid) === ['debit' => 12345, 'credit' => 12345],
    'A valid balanced accounting entry was rejected or totalled incorrectly.'
);

$invalidEntries = [
    [],
    [['account' => '411000', 'label' => 'Client', 'debit' => 100, 'credit' => 0]],
    [['account' => '411000', 'label' => 'Client', 'debit' => 0, 'credit' => 100]],
    [['account' => '411000', 'label' => 'Client', 'debit' => 100, 'credit' => 100]],
    [['account' => '411000', 'label' => 'Client', 'debit' => -100, 'credit' => 0]],
    [['account' => '411000', 'label' => 'Client', 'debit' => 100.0, 'credit' => 0]],
    [['account' => '411000', 'label' => 'Client', 'debit' => '100', 'credit' => 0]],
    [['account' => '', 'label' => 'Client', 'debit' => 100, 'credit' => 0]],
    [['account' => '411000', 'label' => '  ', 'debit' => 100, 'credit' => 0]],
    [['label' => 'Client', 'debit' => 100, 'credit' => 0]],
    [['account' => '411000', 'debit' => 100, 'credit' => 0]],
    [['account' => '411000', 'label' => 'Client', 'debit' => 100, 'credit' => 0], ['account' => '707000', 'label' => 'Vente', 'debit' => 0, 'credit' => 99]],
    [
        ['account' => '411000', 'label' => 'Client A', 'debit' => 99999999999999, 'credit' => 0],
        ['account' => '411000', 'label' => 'Client B', 'debit' => 1, 'credit' => 0],
        ['account' => '707000', 'label' => 'Ventes', 'debit' => 0, 'credit' => 100000000000000],
    ],
];

foreach ($invalidEntries as $index => $entry) {
    $rejected = false;
    try {
        AccountingEntryValidator::validate($entry);
    } catch (Throwable) {
        $rejected = true;
    }

    expectAccountingEntry($rejected, 'Invalid accounting entry was accepted at case '.$index.'.');
}

echo "Accounting entry balance and line invariant smoke tests passed.\n";
