<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

function expectTrialBalanceCsv(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$controller = file_get_contents(dirname(__DIR__).'/src/Controller/GeneralLedgerController.php');
$template = file_get_contents(dirname(__DIR__).'/templates/general_ledger/index.html.twig');
expectTrialBalanceCsv(is_string($controller) && is_string($template), 'Unable to inspect trial balance CSV implementation.');
expectTrialBalanceCsv(
    str_contains($controller, "name: 'app_general_ledger_export'"),
    'Trial balance CSV route is missing.'
);
expectTrialBalanceCsv(
    str_contains($controller, "'Content-Type' => 'text/csv; charset=UTF-8'"),
    'CSV export must declare UTF-8 content.'
);
expectTrialBalanceCsv(
    str_contains($controller, "fwrite($stream, \"\\xEF\\xBB\\xBF\")"),
    'CSV export must include a UTF-8 BOM for spreadsheet compatibility.'
);
expectTrialBalanceCsv(
    str_contains($controller, 'Solde antérieur débiteur'),
    'CSV export must include opening balances.'
);
expectTrialBalanceCsv(
    str_contains($controller, 'Mouvements débit'),
    'CSV export must include period movements.'
);
expectTrialBalanceCsv(
    str_contains($controller, 'Solde clôture débiteur'),
    'CSV export must include closing balances.'
);
expectTrialBalanceCsv(
    str_contains($controller, 'safeCsvText((string) $account[\'name\'])'),
    'Account names must be protected against spreadsheet formula injection.'
);
expectTrialBalanceCsv(
    str_contains($template, "path('app_general_ledger_export',{from:from,to:to})"),
    'The general ledger page must expose the CSV download with current filters.'
);

echo "Trial balance CSV export smoke tests passed.\\n";
