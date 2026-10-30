<?php

declare(strict_types=1);

use App\Entity\Product;
use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderLine;
use App\Entity\SupplierInvoice;
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
$invoice->receive(new DateTimeImmutable('2026-10-01'), new DateTimeImmutable('2026-10-31'));
$payment = new SupplierPayment($invoice, '30.00', new DateTimeImmutable('2026-10-10'), 'bank_transfer', 'VIR-001');
$invoice->addPayment($payment);
expectSupplierInvoice($invoice->getStatus() === SupplierInvoice::STATUS_PARTIALLY_PAID, 'Partial supplier payment status is incorrect.');
expectSupplierInvoice($invoice->getPaidAmount() === 30.0 && $invoice->getRemainingAmount() === 60.0, 'Supplier payment balance is incorrect.');
$invoice->addPayment(new SupplierPayment($invoice, '60.00', new DateTimeImmutable('2026-10-15'), 'bank_transfer'));
expectSupplierInvoice($invoice->getStatus() === SupplierInvoice::STATUS_PAID, 'Fully paid supplier invoice must be marked paid.');
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
