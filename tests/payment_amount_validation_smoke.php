<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use App\Entity\SalesInvoice;
use App\Entity\SalesPayment;
use App\Entity\SupplierInvoice;
use App\Entity\SupplierPayment;

function expectPaymentAmountAccepted(callable $factory, string $amount): void
{
    try {
        $factory($amount);
    } catch (Throwable $exception) {
        throw new RuntimeException(sprintf('Expected amount "%s" to be accepted: %s', $amount, $exception->getMessage()));
    }
}

function expectPaymentAmountRejected(callable $factory, string $amount): void
{
    try {
        $factory($amount);
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException(sprintf('Expected amount "%s" to be rejected.', $amount));
}

$salesInvoice = (new ReflectionClass(SalesInvoice::class))->newInstanceWithoutConstructor();
$supplierInvoice = (new ReflectionClass(SupplierInvoice::class))->newInstanceWithoutConstructor();
$date = new DateTimeImmutable('2026-10-30');

$salesFactory = static fn (string $amount) => new SalesPayment($salesInvoice, $amount, $date, 'bank_transfer');
$supplierFactory = static fn (string $amount) => new SupplierPayment($supplierInvoice, $amount, $date, 'bank_transfer');

foreach ([$salesFactory, $supplierFactory] as $factory) {
    foreach (['1', '1.2', '1.20', '9999999999.99'] as $amount) {
        expectPaymentAmountAccepted($factory, $amount);
    }
    foreach (['', '0', '-1', '+1', '1e3', '1.234', '10000000000', '.50', '01.00', ' 1.00 '] as $amount) {
        expectPaymentAmountRejected($factory, $amount);
    }
}

echo "Payment amount validation smoke test passed.\n";
