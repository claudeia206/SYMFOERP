<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use App\Entity\AccountingAccount;
use App\Entity\AccountingEntry;
use App\Entity\AccountingEntryLine;
use App\Entity\AccountingFiscalYear;
use App\Entity\AccountingJournal;
use InvalidArgumentException;
function expectAccountingFailure(callable $callback,string $case):void{try{$callback();throw new RuntimeException('Expected validation failure: '.$case);}catch(InvalidArgumentException){}}
$bank=new AccountingAccount('512','Banque');
$sales=new AccountingAccount('707','Ventes de marchandises');
$debit=new AccountingEntryLine($bank,'Encaissement','1250.35','0',1);
$credit=new AccountingEntryLine($sales,'Vente','0','1250.35',2);
if($debit->getDebitCents()!==125035||$credit->getCreditCents()!==125035)throw new RuntimeException('Exact cents conversion failed.');
$debit->setMatchingCode('LET-0123456789ABCDEF');
if($debit->getMatchingCode()!=='LET-0123456789ABCDEF')throw new RuntimeException('Accounting matching reference was not stored.');
expectAccountingFailure(fn()=>$debit->setMatchingCode('LET-FEDCBA9876543210'),'prevent rematching a line');
expectAccountingFailure(fn()=>new AccountingEntryLine($bank,'Bad amount','1e3','0',1),'scientific notation');
expectAccountingFailure(fn()=>new AccountingEntryLine($bank,'Bad precision','1.001','0',1),'more than two decimals');
expectAccountingFailure(fn()=>new AccountingEntryLine($bank,'Both sides','1','1',1),'debit and credit together');
expectAccountingFailure(fn()=>new AccountingEntryLine($bank,'Zero','0','0',1),'zero line');
$journal=new AccountingJournal('VE','Ventes');
$year=new AccountingFiscalYear('2026',new DateTimeImmutable('2026-01-01'),new DateTimeImmutable('2026-12-31'));
$entry=new AccountingEntry($journal,$year,'VE-2026-000001',new DateTimeImmutable('2026-10-30'),'Vente comptant');
$entry->setSource('sales_invoice',42);
if($entry->getSourceType()!=='sales_invoice'||$entry->getSourceId()!==42)throw new RuntimeException('Accounting source tracking failed.');
$entry->addLine($debit)->addLine($credit);
$entry->validateForPosting()->post();
if($entry->getStatus()!=='posted'||$entry->totals()!==['debit'=>125035,'credit'=>125035])throw new RuntimeException('Balanced posting failed.');
$unbalanced=new AccountingEntry($journal,$year,'VE-2026-000002',new DateTimeImmutable('2026-10-30'),'Écriture déséquilibrée');
$unbalanced->addLine(new AccountingEntryLine($bank,'Débit','100','0',1));
$unbalanced->addLine(new AccountingEntryLine($sales,'Crédit','0','99.99',2));
expectAccountingFailure(fn()=>$unbalanced->validateForPosting(),'unbalanced entry');
echo "Accounting domain smoke test passed.
";