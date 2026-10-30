<?php

declare(strict_types=1);

use App\Entity\Product;
use App\Entity\ProductStockMovement;
use App\Entity\PurchaseOrderLine;
use App\Entity\SalesOrderLine;

require dirname(__DIR__).'/vendor/autoload.php';

function expectStock(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectStockInvalid(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException($message);
}

$product = new Product('STOCK-SMOKE-001', 'Produit de test');
$product->adjustStock('10.000');
$product->adjustStock('-2.125');
expectStock($product->getStockQuantity() === '7.875', 'Stock adjustments must preserve three decimal places.');

expectStockInvalid(
    static fn () => $product->adjustStock('1e3'),
    'Scientific notation must be rejected for stock adjustments.'
);
expectStockInvalid(
    static fn () => $product->adjustStock('0.0001'),
    'More than three decimal places must be rejected.'
);
expectStockInvalid(
    static fn () => new ProductStockMovement($product, '1e3', 'purchase'),
    'Scientific notation must be rejected in stock movement records.'
);
expectStockInvalid(
    static fn () => new PurchaseOrderLine($product, '1e3', 1),
    'Scientific notation must be rejected in purchase order quantities.'
);
expectStockInvalid(
    static fn () => new SalesOrderLine('Produit', '1e3', '10.00', '20.00', 1, $product),
    'Scientific notation must be rejected in sales order quantities.'
);

$purchaseLine = new PurchaseOrderLine($product, '2.500', 1);
$purchaseLine->receive('1.250');
expectStock($purchaseLine->getReceivedQuantity() === '1.250', 'Valid partial purchase receipts must remain supported.');

echo "Stock decimal validation smoke tests passed.\\n";
