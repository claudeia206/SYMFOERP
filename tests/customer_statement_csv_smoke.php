<?php
declare(strict_types=1);

use App\Service\CustomerStatementCsvFormatter;

require dirname(__DIR__).'/vendor/autoload.php';

function expectStatementCsv(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$csv = CustomerStatementCsvFormatter::format([
    [
        'movement_date' => '2026-11-01',
        'kind' => 'invoice',
        'document' => 'FAC-2026-000001',
        'description' => 'Facture client',
        'debit' => '120,00',
        'credit' => '0,00',
        'balance' => '120,00',
    ],
    [
        'movement_date' => '2026-11-02',
        'kind' => 'payment',
        'document' => '=HYPERLINK("https://evil.test")',
        'description' => '+Commande importée',
        'debit' => '0,00',
        'credit' => '20,00',
        'balance' => '100,00',
    ],
]);

expectStatementCsv(str_starts_with($csv, "\xEF\xBB\xBF"), 'CSV must include a UTF-8 BOM for spreadsheet compatibility.');
$rows = preg_split('/\r\n|\n|\r/', substr($csv, 3), -1, PREG_SPLIT_NO_EMPTY);
expectStatementCsv(is_array($rows) && count($rows) === 3, 'CSV must contain one header and two movements.');
$header = str_getcsv($rows[0], ';', '"', '');
expectStatementCsv($header === ['Date', 'Type', 'Document', 'Description', 'Débit (€)', 'Crédit (€)', 'Solde (€)'], 'CSV must use the French semicolon-separated header.');
$invoice = str_getcsv($rows[1], ';', '"', '');
expectStatementCsv($invoice === ['2026-11-01', 'Facture', 'FAC-2026-000001', 'Facture client', '120,00', '0,00', '120,00'], 'Invoice movement must preserve debit and balance.');
$payment = str_getcsv($rows[2], ';', '"', '');
expectStatementCsv($payment[2] === "'=HYPERLINK(\"https://evil.test\")", 'Formula-like document values must be neutralized.');
expectStatementCsv($payment[3] === "'+Commande importée", 'Formula-like descriptions must be neutralized.');
echo "Customer statement CSV encoding, amounts and spreadsheet formula safety passed.\n";
