<?php

declare(strict_types=1);

use App\Entity\Product;
use App\Entity\ProductStockMovement;

require dirname(__DIR__).'/vendor/autoload.php';

function expectProduct(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$product = new Product('SKU-001', 'Produit de test');
$product->update([
    'sku' => 'SKU-001', 'name' => 'Produit de test', 'unit' => 'pièce',
    'purchase_price' => '12.50', 'sale_price' => '25.00', 'vat_rate' => '20',
    'stock_tracked' => '1', 'stock_alert_threshold' => '3', 'active' => '1',
]);
expectProduct($product->getSalePrice() === '25.00', 'Sale price must be normalized.');
expectProduct($product->getVatRate() === '20.00', 'VAT rate must be normalized.');
expectProduct($product->isLowStock(), 'Zero stock must trigger the configured threshold.');
$product->adjustStock('10');
expectProduct($product->getStockQuantity() === '10.000', 'Positive stock adjustment must increase quantity.');
$product->adjustStock('-4.5');
expectProduct($product->getStockQuantity() === '5.500', 'Negative stock adjustment must decrease quantity.');
expectProduct(!$product->isLowStock(), 'Stock above threshold must not trigger low-stock warning.');
new ProductStockMovement($product, '10', 'purchase', 'Réception fournisseur');
new ProductStockMovement($product, '-4.5', 'sale', 'Sortie');
foreach ([
    fn () => $product->adjustStock('-6'),
    fn () => $product->adjustStock('0'),
    fn () => $product->update(['sku'=>'SKU-001','name'=>'Test','unit'=>'pièce','purchase_price'=>'-1','sale_price'=>'1','vat_rate'=>'20','stock_tracked'=>'1','stock_alert_threshold'=>'0','active'=>'1']),
    fn () => new ProductStockMovement($product, '1', 'unknown'),
] as $invalid) {
    try {
        $invalid();
        throw new RuntimeException('An invalid product or stock operation was accepted.');
    } catch (InvalidArgumentException) {
        // Expected validation failure.
    }
}
$product->setActive(false);
expectProduct(!$product->isActive(), 'Archived product must be inactive.');
echo "Product catalogue and stock invariants passed.\n";
