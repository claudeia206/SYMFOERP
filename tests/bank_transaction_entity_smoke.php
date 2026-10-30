<?php
declare(strict_types=1);
use App\Entity\BankTransaction;
use App\Entity\SalesInvoice;
use App\Entity\SalesOrder;
use App\Entity\SalesPayment;
use App\Entity\SalesQuote;
use App\Entity\SalesQuoteLine;
use App\Entity\ThirdParty;
require dirname(__DIR__).'/vendor/autoload.php';
function expectBank(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$customer=new ThirdParty('Client rapprochement',ThirdParty::CATEGORY_CUSTOMER);
$quote=new SalesQuote($customer);$quote->addLine(new SalesQuoteLine('Prestation','2','50.00','20.00',1));$quote->setStatus(SalesQuote::STATUS_SENT)->setStatus(SalesQuote::STATUS_ACCEPTED);
$order=new SalesOrder($quote);$invoice=new SalesInvoice($order);
$invoice->issue('FAC-2026-000002',new DateTimeImmutable('2026-10-30'),new DateTimeImmutable('2026-11-29'),['legal_name'=>'Test'],['name'=>'Client','address'=>'Rue test','postal_code'=>'17000','city'=>'La Rochelle']);
$payment=new SalesPayment($invoice,'40.00',new DateTimeImmutable('2026-10-30'),'bank_transfer','VIR-TEST');$invoice->addPayment($payment);
$bank=new BankTransaction(new DateTimeImmutable('2026-10-30'),'VIREMENT CLIENT','40.00','VIR-TEST');
expectBank(!$bank->isMatched(),'Imported bank transaction must start unmatched.');
$bank->matchSalesPayment($payment);
expectBank($bank->isMatched()&&$bank->getSalesPayment()===$payment,'Incoming bank transaction must match the selected sales payment.');
try{$bank->matchSalesPayment($payment);throw new RuntimeException('A bank transaction was matched twice.');}catch(InvalidArgumentException){}
$wrongDirection=new BankTransaction(new DateTimeImmutable('2026-10-30'),'SORTIE BANQUE','-40.00');
try{$wrongDirection->matchSalesPayment($payment);throw new RuntimeException('Outgoing transaction matched a customer receipt.');}catch(InvalidArgumentException){}
$wrongAmount=new BankTransaction(new DateTimeImmutable('2026-10-30'),'VIREMENT CLIENT','41.00');
try{$wrongAmount->matchSalesPayment($payment);throw new RuntimeException('Mismatched transaction amount was accepted.');}catch(InvalidArgumentException){}
$duplicate=new BankTransaction(new DateTimeImmutable('2026-10-30'),'VIREMENT CLIENT','40.00','VIR-TEST');
expectBank($bank->getFingerprint()===$duplicate->getFingerprint(),'Identical statement rows must produce the same fingerprint.');
echo "Bank transaction fingerprinting and matching invariants passed.\n";
