<?php

declare(strict_types=1);

require dirname(__DIR__).'/src/Service/StockQuantity.php';
require dirname(__DIR__).'/src/Entity/Product.php';

use App\Service\StockQuantity;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(StockQuantity::toMilliunits('12') === 12000, 'Integer quantities must be converted exactly.');
$assert(StockQuantity::toMilliunits('12.3') === 12300, 'One decimal place must be scaled exactly.');
$assert(StockQuantity::toMilliunits('0.001') === 1, 'Smallest supported quantity must be preserved.');
$assert(StockQuantity::toMilliunits('-1.125', true) === -1125, 'Negative movements must be preserved.');
$assert(StockQuantity::format(-1125) === '-1.125', 'Negative quantities must be formatted exactly.');
$assert(StockQuantity::format(12345) === '12.345', 'Quantities must be formatted with three decimals.');

foreach (['1e3', '1,25', '1.0001', '-1', '', '1000000000'] as $invalid) {
    try {
        StockQuantity::toMilliunits($invalid);
        throw new RuntimeException('Invalid stock quantity was accepted: '.$invalid);
    } catch (\InvalidArgumentException) {
    }
}

try {
    StockQuantity::toMilliunits('-0.001');
    throw new RuntimeException('Negative quantity was accepted for an inbound quantity.');
} catch (\InvalidArgumentException) {
}

$productValues = [
    'sku' => 'SKU-STOCK-TEST',
    'name' => 'Produit stock test',
    'unit' => 'pièce',
    'purchase_price' => '9999999999.99',
    'sale_price' => '12.30',
    'vat_rate' => '20.00',
    'stock_tracked' => '1',
    'stock_alert_threshold' => '0',
    'active' => '1',
];
$product = new \App\Entity\Product('SKU-STOCK-TEST', 'Produit stock test');
$product->update($productValues);
$assert($product->getPurchasePrice() === '9999999999.99', 'Maximum product price must be stored without float conversion.');
foreach (['1e3', '1,25', '01.00', '1.001', '10000000000.00', 1.25] as $invalidPrice) {
    $invalidValues = $productValues;
    $invalidValues['purchase_price'] = $invalidPrice;
    try {
        (new \App\Entity\Product('SKU-STOCK-TEST', 'Produit stock test'))->update($invalidValues);
        throw new RuntimeException('Invalid product price was accepted: '.var_export($invalidPrice, true));
    } catch (\InvalidArgumentException) {
    }
}
$invalidVatValues = $productValues;
$invalidVatValues['vat_rate'] = '100.01';
try {
    (new \App\Entity\Product('SKU-STOCK-TEST', 'Produit stock test'))->update($invalidVatValues);
    throw new RuntimeException('VAT rate above 100 percent was accepted.');
} catch (\InvalidArgumentException) {
}

echo "Exact stock quantity smoke checks passed.\n";
