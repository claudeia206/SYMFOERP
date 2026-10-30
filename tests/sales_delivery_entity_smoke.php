<?php
declare(strict_types=1);
use App\Entity\Product;
use App\Entity\ProductStockMovement;
use App\Entity\SalesDelivery;
use App\Entity\SalesDeliveryLine;
use App\Entity\SalesOrder;
use App\Entity\SalesQuote;
use App\Entity\SalesQuoteLine;
use App\Entity\ThirdParty;
require dirname(__DIR__).'/vendor/autoload.php';
function expectDelivery(bool $condition,string $message): void { if(!$condition) throw new RuntimeException($message); }
$customer=new ThirdParty('Client livraison',ThirdParty::CATEGORY_CUSTOMER);
$product=new Product('SKU-LIVRAISON','Pièce détachée');
$product->update(['sku'=>'SKU-LIVRAISON','name'=>'Pièce détachée','unit'=>'pièce','purchase_price'=>'4','sale_price'=>'10','vat_rate'=>'20','stock_tracked'=>'1','stock_alert_threshold'=>'0','active'=>'1']);
$product->adjustStock('12');
$quote=new SalesQuote($customer);
$quoteLine=new SalesQuoteLine('Pièce détachée','8','10.00','20.00',1);
$quoteLine->setProduct($product); $quote->addLine($quoteLine);
$quote->setStatus(SalesQuote::STATUS_SENT); $quote->setStatus(SalesQuote::STATUS_ACCEPTED);
$order=new SalesOrder($quote); $orderLine=$order->getLines()->first();
$first=new SalesDelivery($order,'Premier envoi');
$firstLine=new SalesDeliveryLine($orderLine,'3');
$orderLine->recordDelivery('3'); $first->addLine($firstLine);
$product->adjustStock('-3');
$movement=new ProductStockMovement($product,'-3','sale','Bon de livraison test');
expectDelivery($orderLine->getDeliveredQuantity()==='3.000','Delivered quantity must be accumulated on order line.');
expectDelivery($orderLine->getRemainingQuantity()==='5.000','Remaining quantity must be reduced after partial delivery.');
expectDelivery($product->getStockQuantity()==='9.000','Tracked stock must be reduced by the shipped quantity.');
expectDelivery($first->getLines()->count()===1 && $firstLine->getQuantity()==='3.000','Delivery must retain a line snapshot and quantity.');
expectDelivery($movement->getReason()==='sale','Delivery stock movement must use sale reason.');
try { new SalesDeliveryLine($orderLine,'5.001'); throw new RuntimeException('Over-delivery was accepted.'); } catch(InvalidArgumentException) {}
$second=new SalesDelivery($order,'Solde de commande');
$secondLine=new SalesDeliveryLine($orderLine,'5');
$orderLine->recordDelivery('5'); $second->addLine($secondLine); $product->adjustStock('-5');
expectDelivery($orderLine->getRemainingQuantity()==='0.000','Remaining quantity must reach zero after final delivery.');
expectDelivery($orderLine->getDeliveredQuantity()==='8.000','Delivered quantity must equal the ordered quantity after two deliveries.');
expectDelivery($product->getStockQuantity()==='4.000','Stock must only decrease by delivered quantities.');
foreach(['0','-1','0.0004'] as $invalid) {
    try { new SalesDeliveryLine($orderLine,$invalid); throw new RuntimeException('Invalid delivery quantity was accepted.'); } catch(InvalidArgumentException) {}
}
try { $closedDelivery = new SalesDelivery($order,'Commande close'); $closedDelivery->addLine(new SalesDeliveryLine($orderLine,'1')); throw new RuntimeException('Unexpected delivery accepted.'); } catch(InvalidArgumentException) {}
echo "Customer delivery partial-quantity and stock invariants passed.\n";
