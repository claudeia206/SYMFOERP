<?php

declare(strict_types=1);

use App\Service\AccountingPostingService;
use App\Controller\GeneralLedgerController;

require dirname(__DIR__).'/vendor/autoload.php';

function expectPostingValidation(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$reflection = new ReflectionClass(AccountingPostingService::class);
$service = $reflection->newInstanceWithoutConstructor();

$cents = $reflection->getMethod('cents');
$cents->setAccessible(true);
$date = $reflection->getMethod('validatedDate');
$date->setAccessible(true);

foreach ([
    ['0', 0],
    ['12', 1200],
    ['12.3', 1230],
    ['12.34', 1234],
    ['999999999999.99', 99999999999999],
    [42, 4200],
] as [$input, $expected]) {
    expectPostingValidation($cents->invoke($service, $input) === $expected, 'Valid monetary amount was converted incorrectly.');
}

foreach ([
    '',
    ' ',
    '-1',
    '+1.00',
    '01.00',
    '1,00',
    '1.001',
    '1e2',
    '1000000000000.00',
    1.25,
    null,
    true,
] as $invalid) {
    $rejected = false;
    try {
        $cents->invoke($service, $invalid);
    } catch (RuntimeException) {
        $rejected = true;
    }
    expectPostingValidation($rejected, 'Invalid monetary input was accepted: '.var_export($invalid, true));
}

$ledgerController = (new ReflectionClass(GeneralLedgerController::class))->newInstanceWithoutConstructor();
$ledgerAmount = (new ReflectionClass(App\Controller\\GeneralLedgerController::class))->getMethod('toCents');
$ledgerAmount->setAccessible(true);
foreach ([
    ['12,34', 1234],
    ['1 234,56', 123456],
    ["1\u{00A0}234,56", 123456],
    ['999 999 999 999,99', 99999999999999],
] as [$input, $expected]) {
    expectPostingValidation($ledgerAmount->invoke($ledgerController, $input) === $expected, 'Valid French ledger amount was converted incorrectly.');
}
foreach (['1 2,00', '12 34,56', '1 2345,00', '01,00', '1,234', '1e3', '1000000000000,00'] as $invalid) {
    $rejected = false;
    try {
        $ledgerAmount->invoke($ledgerController, $invalid);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    expectPostingValidation($rejected, 'Malformed French ledger amount was accepted: '.$invalid);
}

foreach (['2026-01-01', '2000-02-29', '2024-12-31'] as $validDate) {
    expectPostingValidation($date->invoke($service, $validDate) === $validDate, 'Valid accounting date was rejected.');
}

foreach (['2026-02-29', '2026-04-31', '2026-13-01', '01-01-2026', '2026-1-01', ''] as $invalidDate) {
    $rejected = false;
    try {
        $date->invoke($service, $invalidDate);
    } catch (RuntimeException) {
        $rejected = true;
    }
    expectPostingValidation($rejected, 'Invalid accounting date was accepted: '.$invalidDate);
}

echo "Accounting posting amount and date validation smoke tests passed.\n";
