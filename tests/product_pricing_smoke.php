<?php
declare(strict_types=1);

require dirname(__DIR__).'/src/Service/StockQuantity.php';
require dirname(__DIR__).'/src/Entity/Product.php';

use App\Entity\Product;

$base = [
    'sku' => 'SKU-TEST',
    'name' => 'Produit test',
    'unit' => 'pièce',
    'purchase_price' => '12.30',
    'sale_price' => '24.60',
    'vat_rate' => '20.00',
    'stock_tracked' => '1',
    'stock_alert_threshold' => '0',
    'active' => '1',
];

$product = new Product('SKU-TEST', 'Produit test');
$product->update($base);
if ($product->getPurchasePrice() !== '12.30' || $product->getSalePrice() !== '24.60' || $product->getVatRate() !== '20.00') {
    throw new RuntimeException('Product decimal values must retain exact cents.');
}

$maximum = $base;
$maximum['purchase_price'] = '9999999999.99';
$maximum['vat_rate'] = '100.00';
$product->update($maximum);
if ($product->getPurchasePrice() !== '9999999999.99' || $product->getVatRate() !== '100.00') {
    throw new RuntimeException('Maximum supported product price and VAT rate must be accepted.');
}

foreach (['1e3', '1,25', '01.00', '-1', '1.001', '10000000000.00', 1.25, 100000000000] as $invalid) {
    foreach (['purchase_price', 'sale_price'] as $field) {
        $values = $base;
        $values[$field] = $invalid;
        try {
            (new Product('SKU-TEST', 'Produit test'))->update($values);
            throw new RuntimeException('Invalid product price was accepted: '.var_export($invalid, true));
        } catch (InvalidArgumentException) {
        }
    }
}

foreach (['1e1', '100.01', '-1', '01.00', 20.5] as $invalidVat) {
    $values = $base;
    $values['vat_rate'] = $invalidVat;
    try {
        (new Product('SKU-TEST', 'Produit test'))->update($values);
        throw new RuntimeException('Invalid VAT rate was accepted: '.var_export($invalidVat, true));
    } catch (InvalidArgumentException) {
    }
}

echo "Product exact-decimal pricing smoke tests passed.\n";
