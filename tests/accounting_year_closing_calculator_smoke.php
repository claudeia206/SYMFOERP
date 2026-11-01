<?php

declare(strict_types=1);

use App\Service\AccountingYearClosingCalculator;

require dirname(__DIR__).'/vendor/autoload.php';

function expectYearClosing(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$profitLines = AccountingYearClosingCalculator::closingLines([
    ['account_id' => 1, 'code' => '607000', 'name' => 'Achats', 'account_class' => 6, 'debit' => 70000, 'credit' => 0],
    ['account_id' => 2, 'code' => '707000', 'name' => 'Ventes', 'account_class' => 7, 'debit' => 0, 'credit' => 120000],
], 3, 4);
expectYearClosing(count($profitLines) === 3, 'Profit closing must close income, expense and result accounts.');
expectYearClosing($profitLines[0]['credit'] === 70000, 'Expense debit balance must be credited to close.');
expectYearClosing($profitLines[1]['debit'] === 120000, 'Income credit balance must be debited to close.');
expectYearClosing($profitLines[2]['account_id'] === 3 && $profitLines[2]['credit'] === 50000, 'Profit must be transferred to account 120000.');

$lossLines = AccountingYearClosingCalculator::closingLines([
    ['account_id' => 1, 'code' => '607000', 'name' => 'Achats', 'account_class' => 6, 'debit' => 120000, 'credit' => 0],
    ['account_id' => 2, 'code' => '707000', 'name' => 'Ventes', 'account_class' => 7, 'debit' => 0, 'credit' => 70000],
], 3, 4);
expectYearClosing($lossLines[2]['account_id'] === 4 && $lossLines[2]['debit'] === 50000, 'Loss must be transferred to account 129000.');

$openingLines = AccountingYearClosingCalculator::openingLines([
    ['account_id' => 10, 'code' => '512000', 'name' => 'Banque', 'account_class' => 5, 'debit' => 100000, 'credit' => 0],
    ['account_id' => 11, 'code' => '411000', 'name' => 'Clients', 'account_class' => 4, 'debit' => 50000, 'credit' => 0],
    ['account_id' => 12, 'code' => '401000', 'name' => 'Fournisseurs', 'account_class' => 4, 'debit' => 0, 'credit' => 70000],
    ['account_id' => 13, 'code' => '101000', 'name' => 'Capital', 'account_class' => 1, 'debit' => 0, 'credit' => 30000],
    ['account_id' => 14, 'code' => '120000', 'name' => 'Résultat bénéficiaire', 'account_class' => 1, 'debit' => 0, 'credit' => 50000],
]);
expectYearClosing(count($openingLines) === 5, 'All non-zero balance-sheet accounts must be carried forward.');
expectYearClosing($openingLines[0]['debit'] === 100000, 'Debit balance must remain debit in opening entry.');
expectYearClosing($openingLines[2]['credit'] === 70000, 'Credit balance must remain credit in opening entry.');

try {
    AccountingYearClosingCalculator::openingLines([
        ['account_id' => 1, 'code' => '707000', 'name' => 'Ventes', 'account_class' => 7, 'debit' => 0, 'credit' => 100],
    ]);
    throw new RuntimeException('Profit and loss accounts must not be carried into opening balances.');
} catch (InvalidArgumentException) {
}

try {
    AccountingYearClosingCalculator::openingLines([
        ['account_id' => 1, 'code' => '512000', 'name' => 'Banque', 'account_class' => 5, 'debit' => 100, 'credit' => 0],
    ]);
    throw new RuntimeException('Unbalanced opening balances must be rejected.');
} catch (RuntimeException $exception) {
    expectYearClosing(str_contains($exception->getMessage(), 'déséquilibrée'), 'Unbalanced opening balance must fail for the correct reason.');
}

echo "Fiscal year profit/loss closing and opening balance calculations passed.\n";
