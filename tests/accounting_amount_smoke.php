<?php
declare(strict_types=1);

use App\Service\AccountingAmount;

require dirname(__DIR__).'/vendor/autoload.php';

function expectAccountingAmount(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

foreach ([
    ['0', 0],
    ['12', 1200],
    ['12.3', 1230],
    ['-12.34', -1234],
    ['9999999999999.99', 999999999999999],
    [42, 4200],
] as [$value, $expected]) {
    expectAccountingAmount(AccountingAmount::toCents($value) === $expected, 'Exact cent conversion failed.');
}

foreach ([
    [0, '0.00'],
    [1234, '12.34'],
    [-1234, '-12.34'],
    [999999999999999, '9999999999999.99'],
] as [$value, $expected]) {
    expectAccountingAmount(AccountingAmount::formatCents($value) === $expected, 'Exact cent formatting failed.');
}

expectAccountingAmount(AccountingAmount::addCents(1200, 345) === 1545, 'Exact-cent addition failed.');
expectAccountingAmount(AccountingAmount::addCents(99999999999999) === 99999999999999, 'Maximum journal amount must be supported.');
foreach ([[-1], [99999999999999, 1]] as $invalidSum) {
    try {
        AccountingAmount::addCents(...$invalidSum);
        throw new RuntimeException('Out-of-range accounting total accepted.');
    } catch (InvalidArgumentException) {
    }
}

foreach (['', ' ', '+1.00', '01.00', '1,00', '1.001', '1e3', '10000000000000.00', 1.25, null, true] as $invalid) {
    try {
        AccountingAmount::toCents($invalid);
        throw new RuntimeException('Invalid accounting amount accepted: '.var_export($invalid, true));
    } catch (InvalidArgumentException) {
    }
}

echo "Accounting exact-cent arithmetic smoke tests passed.\n";
