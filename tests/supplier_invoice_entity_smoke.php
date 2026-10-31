<?php

declare(strict_types=1);

use App\Entity\Product;
use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderLine;
use App\Entity\SupplierInvoice;
use App\Entity\SupplierInvoiceLine;
use App\Entity\SupplierPayment;
use App\Entity\ThirdParty;

require dirname(__DIR__).'/vendor/autoload.php';

function expectSupplierInvoice(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$supplier = new ThirdParty('Fournisseur facture test', ThirdParty::CATEGORY_SUPPLIER);
$product = new Product('SKU-FAF', 'Composant');
$product->update(['sku'=>'SKU-FAF','name'=>'Composant','unit'=>'pièce','purchase_price'=>'25.00','sale_price'=>'40','vat_rate'=>'20','stock_tracked'=>'1','stock_alert_threshold'=>'0','active'=>'1']);
$order = new PurchaseOrder($supplier);
$line = new PurchaseOrderLine($product, '5', 1);
foreach (['1e3', '0.0001', '1,000'] as $invalidQuantity) {
    try {
        new SupplierInvoiceLine($product, 'Quantité invalide', $invalidQuantity, '25.00', '20.00', 1);
        throw new RuntimeException('Invalid supplier invoice quantity was accepted: '.$invalidQuantity);
    } catch (InvalidArgumentException) {
    }
}

$order->addLine($line);
$order->setStatus(PurchaseOrder::STATUS_SENT);
try {
    new SupplierInvoice($order, 'F-2026-001');
    throw new RuntimeException('An invoice without a receipt was accepted.');
} catch (InvalidArgumentException) {}
$line->receive('3');
$order->refreshReceiptStatus();
$invoice = new SupplierInvoice($order, 'F-2026-001');
expectSupplierInvoice($invoice->getLines()->count() === 1, 'Received quantities must be copied to supplier invoice lines.');
expectSupplierInvoice($invoice->getLines()->first()->getQuantity() === '3.000', 'Only received quantity may be invoiced.');
expectSupplierInvoice($invoice->getTotalExcludingTax() === 75.0, 'Supplier invoice must snapshot purchase prices.');
expectSupplierInvoice($invoice->getTotalIncludingTax() === 90.0, 'Supplier invoice VAT total must be calculated.');
expectSupplierInvoice($invoice->getTotalExcludingTaxCents() === 7500 && $invoice->getTotalTaxCents() === 1500 && $invoice->getTotalIncludingTaxCents() === 9000, 'Supplier invoice totals must remain exact in cents.');
$normalizedPayment = new SupplierPayment($invoice, '0.5', new DateTimeImmutable('2026-10-10'), 'cash');
expectSupplierInvoice($normalizedPayment->getAmount() === '0.50', 'Supplier payment amounts must be normalized exactly.');
foreach (['0', '-1.00', '+1.00', '01.00', '1,00', '1.001', '1e2', '10000000000.00'] as $invalidAmount) {
    try {
        new SupplierPayment($invoice, $invalidAmount, new DateTimeImmutable('2026-10-10'), 'cash');
        throw new RuntimeException('Invalid supplier payment amount was accepted: '.$invalidAmount);
    } catch (InvalidArgumentException) {
        // Supplier payments require a positive bounded decimal amount.
    }
}
$invoice->receive(new DateTimeImmutable('2026-10-01'), new DateTimeImmutable('2026-10-31'));
$payment = new SupplierPayment($invoice, '30.00', new DateTimeImmutable('2026-10-10'), 'bank_transfer', 'VIR-001');
$invoice->addPayment($payment);
expectSupplierInvoice($invoice->getStatus() === SupplierInvoice::STATUS_PARTIALLY_PAID, 'Partial supplier payment status is incorrect.');
expectSupplierInvoice($invoice->getPaidAmountCents() === 3000 && $invoice->getRemainingAmountCents() === 6000, 'Supplier payment balance is incorrect.');
$invoice->addPayment(new SupplierPayment($invoice, '60.00', new DateTimeImmutable('2026-10-15'), 'bank_transfer'));
expectSupplierInvoice($invoice->getStatus() === SupplierInvoice::STATUS_PAID, 'Fully paid supplier invoice must be marked paid.');
$boundaryInvoice = new SupplierInvoice($order, 'F-2026-002');
$boundaryInvoice->receive(new DateTimeImmutable('2026-10-01'), new DateTimeImmutable('2026-10-31'));
$boundaryInvoice->addPayment(new SupplierPayment($boundaryInvoice, '89.99', new DateTimeImmutable('2026-10-10'), 'bank_transfer'));
expectSupplierInvoice($boundaryInvoice->getRemainingAmountCents() === 1, 'A one-cent supplier balance must remain outstanding exactly.');
try {
    $boundaryInvoice->addPayment(new SupplierPayment($boundaryInvoice, '0.02', new DateTimeImmutable('2026-10-10'), 'cash'));
    throw new RuntimeException('A supplier payment one cent above the balance was accepted.');
} catch (InvalidArgumentException) {
    // Integer-cent comparisons must reject even a one-cent overpayment.
}

foreach ([
    fn () => new SupplierInvoice($order, ''),
    fn () => new SupplierPayment($invoice, '0', new DateTimeImmutable('today'), 'cash'),
    fn () => $invoice->addPayment(new SupplierPayment($invoice, '0.01', new DateTimeImmutable('today'), 'cash')),
    fn () => new SupplierInvoice(new PurchaseOrder(new ThirdParty('Client interdit', ThirdParty::CATEGORY_CUSTOMER)), 'REF'),
] as $invalid) {
    try { $invalid(); throw new RuntimeException('An invalid supplier invoice operation was accepted.'); }
    catch (InvalidArgumentException) {}
}
echo "Supplier invoice snapshots, receipt gating and outgoing payment invariants passed.\n";
