<?php

declare(strict_types=1);

use App\Entity\Product;
use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderLine;
use App\Entity\ProductStockMovement;
use App\Entity\ThirdParty;

require dirname(__DIR__).'/vendor/autoload.php';

function expectPurchase(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$supplier = new ThirdParty('Fournisseur de test', ThirdParty::CATEGORY_SUPPLIER);
$product = new Product('SKU-ACHAT', 'Matière première');
$product->update(['sku'=>'SKU-ACHAT','name'=>'Matière première','unit'=>'pièce','purchase_price'=>'12.50','sale_price'=>'20','vat_rate'=>'20','stock_tracked'=>'1','stock_alert_threshold'=>'2','active'=>'1']);
$order = new PurchaseOrder($supplier);
$line = new PurchaseOrderLine($product, '10', 1);
$order->addLine($line);
$order->setStatus(PurchaseOrder::STATUS_SENT);
expectPurchase($order->getLines()->count() === 1, 'Purchase order line must be attached.');
expectPurchase($line->getRemainingQuantity() === '10.000', 'Initial remaining quantity must equal ordered quantity.');
expectPurchase($order->getTotalExcludingTax() === 125.0, 'Purchase subtotal must use the purchase price snapshot.');
$line->receive('4');
$product->adjustStock('4');
$movement = new ProductStockMovement($product, '4', 'purchase', 'Réception commande fournisseur');
expectPurchase($product->getStockQuantity() === '4.000', 'A tracked-product receipt must increase stock.');
expectPurchase($movement->getReason() === 'purchase', 'Receipt stock movement must use the purchase reason.');
$order->refreshReceiptStatus();
expectPurchase($line->getReceivedQuantity() === '4.000', 'Partial receipt quantity must be recorded.');
expectPurchase($line->getRemainingQuantity() === '6.000', 'Remaining quantity must decrease after receipt.');
expectPurchase($order->getStatus() === PurchaseOrder::STATUS_PARTIALLY_RECEIVED, 'Partial receipt must update order status.');
$line->receive('6');
$order->refreshReceiptStatus();
expectPurchase($order->getStatus() === PurchaseOrder::STATUS_RECEIVED, 'Full receipt must close the order.');
foreach ([
    fn () => $line->receive('0.001'),
    fn () => new PurchaseOrderLine($product, '0.0004', 3),
    fn () => new PurchaseOrderLine($product, '-1', 2),
    fn () => new PurchaseOrder(new ThirdParty('Client interdit', ThirdParty::CATEGORY_CUSTOMER)),
    function () use ($product): void { $product->setActive(false); new PurchaseOrderLine($product, '1', 1); },
] as $invalid) {
    try {
        $invalid();
        throw new RuntimeException('An invalid supplier purchase operation was accepted.');
    } catch (InvalidArgumentException) {
        // Expected validation failure.
    }
}
echo "Supplier purchase order and partial receipt invariants passed.\n";
