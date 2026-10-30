<?php
declare(strict_types=1);

use App\Service\FecExportFormatter;

require dirname(__DIR__).'/vendor/autoload.php';

function expectFec(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$formatter = new FecExportFormatter();

foreach ([
    ['0', '0.00'],
    ['12', '12.00'],
    ['12.3', '12.30'],
    ['0012.30', '12.30'],
    ['9999999999999.99', '9999999999999.99'],
    [42, '42.00'],
] as [$input, $expected]) {
    expectFec($formatter->formatAmount($input) === $expected, 'FEC amount formatting mismatch.');
}

foreach (['', ' ', '-1.00', '+1.00', '1,00', '1.001', '1e3', '10000000000000.00', 1.25, null, true] as $invalid) {
    $rejected = false;
    try {
        $formatter->formatAmount($invalid);
    } catch (RuntimeException) {
        $rejected = true;
    }
    expectFec($rejected, 'Invalid FEC amount accepted: '.var_export($invalid, true));
}

$fields = array_fill(0, 18, '');
$fields[10] = "Libellé | avec\r\nretour\\tfin";
$fields[11] = '120.00';
$line = $formatter->formatRow($fields);
expectFec(substr_count($line, '|') === 17, 'FEC row does not contain exactly 18 columns.');
expectFec(str_contains($line, 'Libellé / avec  retour fin'), 'FEC text field was not sanitized.');

$rejected = false;
try {
    $formatter->formatRow(array_fill(0, 17, ''));
} catch (RuntimeException) {
    $rejected = true;
}
expectFec($rejected, 'FEC row with 17 columns was accepted.');

$document = $formatter->formatDocument([$fields]);
$lines = explode("\r\n", trim($document));
expectFec(count($lines) === 2, 'FEC document must contain one header and one data row.');
expectFec($lines[0] === implode('|', FecExportFormatter::COLUMNS), 'FEC header does not match the 18 expected columns.');
expectFec(substr_count($lines[1], '|') === 17, 'FEC data row does not contain 18 columns.');

echo "FEC export formatter smoke tests passed.\n";
