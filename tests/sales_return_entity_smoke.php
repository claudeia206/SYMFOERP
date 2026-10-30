<?php
declare(strict_types=1);
use App\Entity\Product;
use App\Entity\ProductStockMovement;
use App\Entity\SalesOrder;
use App\Entity\SalesQuote;
use App\Entity\SalesQuoteLine;
use App\Entity\SalesReturn;
use App\Entity\SalesReturnLine;
use App\Entity\ThirdParty;
require dirname(__DIR__).'/vendor/autoload.php';
function expectReturn(bool $condition,string $message): void { if(!$condition) throw new RuntimeException($message); }
$customer=new ThirdParty('Client retour',ThirdParty::CATEGORY_CUSTOMER);
$product=new Product('SKU-RETOUR','Équipement');
$product->update(['sku'=>'SKU-RETOUR','name'=>'Équipement','unit'=>'pièce','purchase_price'=>'20','sale_price'=>'40','vat_rate'=>'20','stock_tracked'=>'1','stock_alert_threshold'=>'0','active'=>'1']);
$product->adjustStock('10');
$quote=new SalesQuote($customer); $quoteLine=new SalesQuoteLine('Équipement','6','40.00','20.00',1); $quoteLine->setProduct($product); $quote->addLine($quoteLine);
$quote->setStatus(SalesQuote::STATUS_SENT); $quote->setStatus(SalesQuote::STATUS_ACCEPTED);
$order=new SalesOrder($quote); $line=$order->getLines()->first();
$line->recordDelivery('6'); $product->adjustStock('-6');
$return=new SalesReturn($order,'Produit endommagé');
$returnLine=new SalesReturnLine($line,'2'); $line->recordReturn('2'); $return->addLine($returnLine);
$product->adjustStock('2');
$movement=new ProductStockMovement($product,'2','return','Retour client test');
expectReturn($line->getDeliveredQuantity()==='6.000','Delivered quantity must remain unchanged after a return.');
expectReturn($line->getReturnedQuantity()==='2.000','Returned quantity must be tracked.');
expectReturn($line->getReturnableQuantity()==='4.000','Returnable quantity must exclude previous returns.');
expectReturn($product->getStockQuantity()==='6.000','Accepted return must restore tracked stock.');
expectReturn($return->getLines()->count()===1 && $returnLine->getQuantity()==='2.000','Return document must keep its line snapshot.');
expectReturn($movement->getReason()==='return' && $movement->getQuantity()==='2.000','Return stock movement must be positive and classified as return.');
try { new SalesReturnLine($line,'4.001'); throw new RuntimeException('Return quantity greater than delivered balance was accepted.'); } catch(InvalidArgumentException) {}
$second=new SalesReturn($order,'Retour du solde');
$secondLine=new SalesReturnLine($line,'4'); $line->recordReturn('4'); $second->addLine($secondLine); $product->adjustStock('4');
expectReturn($line->getReturnableQuantity()==='0.000','All delivered units must be marked returned.');
expectReturn($product->getStockQuantity()==='10.000','All returned stock must be restored exactly once.');
foreach(['0','-1','0.0004'] as $invalid) {
    try { new SalesReturnLine($line,$invalid); throw new RuntimeException('Invalid return quantity was accepted.'); } catch(InvalidArgumentException) {}
}
echo "Customer sales return quantity and stock invariants passed.\n";
