<?php
declare(strict_types=1);

use App\\Service\\CustomerStatementCsvFormatter;

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

expectStatementCsv(str_starts_with($csv, "\\xEF\\xBB\\xBF"), 'CSV must include a UTF-8 BOM for spreadsheet compatibility.');
expectStatementCsv(str_contains($csv, 'Date;Type;Document;Description;Débit (€);Crédit (€);Solde (€)'), 'CSV must use the French semicolon-separated header.');
expectStatementCsv(str_contains($csv, 'Facture;FAC-2026-000001;Facture client;120,00;0,00;120,00'), 'Invoice movement must preserve debit and balance.');
expectStatementCsv(str_contains($csv, "'=HYPERLINK"), 'Formula-like document values must be neutralized.');
expectStatementCsv(str_contains($csv, "'+Commande importée"), 'Formula-like descriptions must be neutralized.');
echo "Customer statement CSV encoding, amounts and spreadsheet formula safety passed.\\n";
