<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

function expectFinancialStatements(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$source = file_get_contents(dirname(__DIR__).'/src/Controller/FinancialStatementsController.php');
expectFinancialStatements(is_string($source), 'Unable to inspect financial statement calculation.');

foreach ([
    'The current-period result belongs in equity',
    '$netResult = AccountingAmount::addSignedCents($totals[\'income\'], -$totals[\'expenses\']);',
    "'code' => 'Résultat'",
    "'name' => 'Résultat de la période (avant affectation)'",
    "\$totals['equity'] = AccountingAmount::addSignedCents(\$totals['equity'], \$netResult);",
    "\$balanceSheetTotal = AccountingAmount::addSignedCents(\$totals['liabilities'], \$totals['equity']);",
] as $required) {
    expectFinancialStatements(str_contains($source, $required), 'Missing balance sheet result integration: '.$required);
}

echo "Financial statement current-period result integration checks passed.\n";
