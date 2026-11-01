<?php

declare(strict_types=1);

use App\Service\AccountingAmount;

require dirname(__DIR__).'/vendor/autoload.php';

function expectFinancialStatements(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$source = file_get_contents(dirname(__DIR__).'/src/Controller/FinancialStatementsController.php');
$template = file_get_contents(dirname(__DIR__).'/templates/accounting/statements.html.twig');
expectFinancialStatements(is_string($source), 'Unable to inspect financial statement calculation.');
expectFinancialStatements(is_string($template), 'Unable to inspect financial statement screen.');

foreach ([
    '$fiscalYearStart = (new DateTimeImmutable($to))->modify(\'first day of january\')->format(\'Y-m-d\');',
    'AS fiscal_debit',
    'AS fiscal_credit',
    '$fiscalExpenses = AccountingAmount::addSignedCents($fiscalExpenses, AccountingAmount::addSignedCents($yearDebit, -$yearCredit));',
    '$fiscalIncome = AccountingAmount::addSignedCents($fiscalIncome, AccountingAmount::addSignedCents($yearCredit, -$yearDebit));',
    '$netResult = AccountingAmount::addSignedCents($totals[\'income\'], -$totals[\'expenses\']);',
    '$balanceSheetResult = AccountingAmount::addSignedCents($fiscalIncome, -$fiscalExpenses);',
    "'name' => 'Résultat de l’exercice (avant affectation)'",
    "\$totals['equity'] = AccountingAmount::addSignedCents(\$totals['equity'], \$balanceSheetResult);",
    "\$balanceSheetTotal = AccountingAmount::addSignedCents(\$totals['liabilities'], \$totals['equity']);",
] as $required) {
    expectFinancialStatements(str_contains($source, $required), 'Missing balance sheet result integration: '.$required);
}

foreach ([
    '$balanceSheetDifference = AccountingAmount::addSignedCents($totals[\'assets\'], -$balanceSheetTotal);',
    "'balance_sheet_balanced' => \$balanceSheetDifference === 0",
    'balance_sheet_difference' => AccountingAmount::formatFrenchCents($balanceSheetDifference),
] as $required) {
    expectFinancialStatements(str_contains($source, $required), 'Missing balance sheet control calculation: '.$required);
}
expectFinancialStatements(str_contains($template, 'Contrôle d’équilibre du bilan'), 'Missing visible balance sheet control.');

echo "Financial statement fiscal-year result integration checks passed.\\n";
