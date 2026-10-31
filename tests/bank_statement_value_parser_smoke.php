<?php
declare(strict_types=1);

use App\Service\BankStatementValueParser;

require dirname(__DIR__).'/vendor/autoload.php';

function expectBankParser(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$parser = new BankStatementValueParser();
expectBankParser(
    $parser->normalizeHeaders(["\xEF\xBB\xBFDate", " Label ", "AMOUNT"]) === ['date', 'label', 'amount'],
    'UTF-8 BOM bank CSV headers were not normalized.'
);
expectBankParser($parser->parseDate('2026-10-30')->format('Y-m-d') === '2026-10-30', 'ISO date parsing failed.');
expectBankParser($parser->parseDate('30/10/2026')->format('Y-m-d') === '2026-10-30', 'French date parsing failed.');

foreach (['2026-02-30', '30/02/2026', '2026-1-02', '1/10/2026', '2026-10-30junk', ''] as $invalidDate) {
    try {
        $parser->parseDate($invalidDate);
        throw new RuntimeException('Invalid bank date accepted: '.$invalidDate);
    } catch (InvalidArgumentException) {
    }
}

$amountCases = [
    ['1', 100],
    ['1.2', 120],
    ['1,20', 120],
    ['1 234,56 €', 123456],
    ['12 345 678,90 €', 1234567890],
    ["1\u{202F}234,56 €", 123456],
    ['-25.50', -2550],
    ['9999999999.99', 999999999999],
];
foreach ($amountCases as [$value, $expected]) {
    expectBankParser($parser->parseAmountToCents($value) === $expected, 'Bank amount parsing failed: '.$value);
}
foreach (['', '0', '-0.00', '+1.00', '1e3', '1.001', '1,2.3', '01.00', '10000000000.00', 'abc', '1 2', '12 34', '1 € 00', '1 2345,00', '1,2,3', '123 456 789 012,00'] as $invalidAmount) {
    try {
        $parser->parseAmountToCents($invalidAmount);
        throw new RuntimeException('Invalid bank amount accepted: '.$invalidAmount);
    } catch (InvalidArgumentException) {
    }
}
expectBankParser($parser->formatCents(-2550) === '-25.50', 'Negative cents formatting failed.');
expectBankParser($parser->formatCents(123456) === '1234.56', 'Positive cents formatting failed.');

echo "Bank statement strict value parser smoke tests passed.\n";
