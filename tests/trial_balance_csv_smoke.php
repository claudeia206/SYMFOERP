<?php

declare(strict_types=1);

use App\Service\TrialBalanceCsvFormatter;

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
    str_contains($controller, 'TrialBalanceCsvFormatter $formatter'),
    'Controller must delegate CSV formatting to its dedicated service.'
);
expectTrialBalanceCsv(
    str_contains($template, "path('app_general_ledger_export',{from:from,to:to})"),
    'The general ledger page must expose the CSV download with current filters.'
);

$csv = (new TrialBalanceCsvFormatter())->format([
    [
        'code' => '411000',
        'name' => '=HYPERLINK("https://example.invalid")',
        'account_class' => 4,
        'opening_debit' => '100.00',
        'opening_credit' => '0.00',
        'period_debit' => '0.00',
        'period_credit' => '20.00',
    ],
    [
        'code' => '401000',
        'name' => 'Fournisseurs',
        'account_class' => 4,
        'opening_debit' => '0.00',
        'opening_credit' => '100.00',
        'period_debit' => '20.00',
        'period_credit' => '0.00',
    ],
]);
expectTrialBalanceCsv(str_starts_with($csv, "\xEF\xBB\xBF"), 'CSV must start with a UTF-8 BOM.');

$stream = fopen('php://temp', 'r+');
fwrite($stream, substr($csv, 3));
rewind($stream);
$header = fgetcsv($stream, 0, ';', '"', '');
$first = fgetcsv($stream, 0, ';', '"', '');
$second = fgetcsv($stream, 0, ';', '"', '');
$total = fgetcsv($stream, 0, ';', '"', '');
fclose($stream);

expectTrialBalanceCsv(is_array($header) && count($header) === 9, 'CSV must contain nine columns.');
expectTrialBalanceCsv(is_array($first) && $first[1] === '\'=HYPERLINK("https://example.invalid")', 'Formula-like account names must be neutralized.');
expectTrialBalanceCsv(is_array($second) && $second[0] === '401000', 'CSV must preserve all account rows.');
expectTrialBalanceCsv(is_array($total) && $total[0] === 'TOTAL', 'CSV must end with a totals row.');
expectTrialBalanceCsv(
    $total[3] === '100,00' && $total[4] === '100,00'
    && $total[5] === '20,00' && $total[6] === '20,00'
    && $total[7] === '80,00' && $total[8] === '80,00',
    'Opening, period and closing totals must be correctly balanced.'
);

echo "Trial balance CSV formatter and export tests passed.\n";
