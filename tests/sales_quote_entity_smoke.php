<?php

declare(strict_types=1);

use App\Entity\SalesQuote;
use App\Entity\SalesQuoteLine;
use App\Entity\SalesOrder;
use App\Entity\ThirdParty;
use App\Entity\Product;
use App\Entity\ProductStockMovement;

require dirname(__DIR__).'/vendor/autoload.php';

function expectSales(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$customer = new ThirdParty('Client de test', ThirdParty::CATEGORY_CUSTOMER);
$quote = new SalesQuote($customer);
$catalogProduct = new Product('SKU-CONSEIL', 'Prestation de conseil');
$catalogProduct->update(['sku'=>'SKU-CONSEIL','name'=>'Prestation de conseil','unit'=>'heure','purchase_price'=>'20','sale_price'=>'50','vat_rate'=>'20','stock_tracked'=>'1','stock_alert_threshold'=>'0','active'=>'1']);
$catalogLine = new SalesQuoteLine('Prestation de conseil', '2', '50.00', '20.00', 1);
$catalogLine->setProduct($catalogProduct);
$quote->addLine($catalogLine);
$catalogProduct->adjustStock('10');
$quote->addLine(new SalesQuoteLine('Frais de dossier', '1', '10.00', '0.00', 2));

expectSales($quote->getStatus() === SalesQuote::STATUS_DRAFT, 'New quotes must start as drafts.');
expectSales($quote->getLines()->count() === 2, 'Quote lines must be attached to their quote.');
expectSales($quote->getTotalExcludingTax() === 110.0, 'The quote subtotal must be calculated correctly.');
expectSales($quote->getTotalTax() === 20.0, 'VAT must be rounded and summed per line.');
expectSales($quote->getTotalIncludingTax() === 130.0, 'The total including VAT must be calculated correctly.');

$quote->setStatus(SalesQuote::STATUS_SENT);
expectSales($quote->getStatus() === SalesQuote::STATUS_SENT, 'Quote status must be mutable to a supported state.');
$quote->setStatus(SalesQuote::STATUS_ACCEPTED);
$order = new SalesOrder($quote);
expectSales($order->getLines()->count() === 2, 'Order conversion must copy all quote lines.');
expectSales($order->getLines()->first()->getProduct() === $catalogProduct, 'Order conversion must preserve the product reference.');
expectSales($order->getTotalExcludingTax() === $quote->getTotalExcludingTax(), 'Converted order subtotal must match the accepted quote.');
expectSales($order->getTotalTax() === $quote->getTotalTax(), 'Converted order VAT must match the accepted quote.');
expectSales($order->getTotalIncludingTax() === $quote->getTotalIncludingTax(), 'Converted order total must match the accepted quote.');
$order->setStatus(SalesOrder::STATUS_FULFILLED);
expectSales($order->getStatus() === SalesOrder::STATUS_FULFILLED, 'Order status must transition to fulfilled.');
$catalogProduct->adjustStock('-2');
$saleMovement = new ProductStockMovement($catalogProduct, '-2', 'sale', 'Livraison commande de test');
expectSales($catalogProduct->getStockQuantity() === '8.000', 'A tracked-product sale must reduce available stock.');
expectSales($saleMovement->getReason() === 'sale' && $saleMovement->getQuantity() === '-2.000', 'Sales stock movement must record a negative quantity.');
try {
    new SalesOrder(new SalesQuote($customer));
    throw new RuntimeException('A draft quote was converted into an order.');
} catch (InvalidArgumentException) {
    // Only accepted quotes may be converted.
}

try {
    $quote->setStatus('unknown');
    throw new RuntimeException('An unsupported quote status was accepted.');
} catch (InvalidArgumentException) {
    // Expected validation failure.
}

try {
    new SalesQuote(new ThirdParty('Prospect de test', ThirdParty::CATEGORY_PROSPECT));
    throw new RuntimeException('A prospect was accepted as a quote customer.');
} catch (InvalidArgumentException) {
    // Only customer records may be quoted.
}

foreach ([
    fn () => new SalesQuoteLine('', '1', '10.00', '20.00', 1),
    fn () => new SalesQuoteLine('Quantité invalide', '0', '10.00', '20.00', 1),
    fn () => new SalesQuoteLine('TVA invalide', '1', '10.00', '120.00', 1),
] as $invalidLine) {
    try {
        $invalidLine();
        throw new RuntimeException('An invalid quote line was accepted.');
    } catch (InvalidArgumentException) {
        // Expected validation failure.
    }
}

echo "Sales quote entity and totals smoke tests passed.\n";
