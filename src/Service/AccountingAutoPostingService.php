<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\AccountingAccount;
use App\Entity\AccountingEntry;
use App\Entity\AccountingEntryLine;
use App\Entity\AccountingFiscalYear;
use App\Entity\AccountingJournal;
use App\Entity\SalesInvoice;
use App\Entity\SalesPayment;
use App\Entity\SupplierInvoice;
use App\Entity\SupplierPayment;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\LockMode;
use InvalidArgumentException;

final class AccountingAutoPostingService
{
 public function __construct(private readonly EntityManagerInterface $em, private readonly AccountingEntryNumberGenerator $numbers) {}

 public function postSalesInvoice(SalesInvoice $invoice): void
 {
  if ($invoice->getId() === null || $invoice->getNumber() === null || !in_array($invoice->getStatus(), [SalesInvoice::STATUS_ISSUED, SalesInvoice::STATUS_PARTIALLY_PAID, SalesInvoice::STATUS_PAID], true)) throw new InvalidArgumentException('La facture client doit être émise et non annulée avant comptabilisation.');
  $net=[];$tax=0;
  foreach($invoice->getLines() as $line){
   $cents=self::cents($line->getTotalExcludingTax());
   $account=$line->getProduct()?->isStockTracked() ? '707' : '706';
   $net[$account]=($net[$account]??0)+$cents;
   $tax+=self::cents($line->getTotalTax());
  }
  $this->post('sales_invoice',$invoice->getId(),'VE',$invoice->getInvoiceDate(),'Facture client '.$invoice->getNumber(),function(AccountingEntry $entry)use($net,$tax,$invoice):void{
   $this->line($entry,'411','Client - '.$invoice->getNumber(),self::cents($invoice->getTotalIncludingTax()),0);
   foreach($net as $account=>$amount)$this->line($entry,$account,'Vente - '.$invoice->getNumber(),0,$amount);
   if($tax>0)$this->line($entry,'44571','TVA collectée - '.$invoice->getNumber(),0,$tax);
  });
 }

 public function postSupplierInvoice(SupplierInvoice $invoice): void
 {
  if ($invoice->getId() === null || !in_array($invoice->getStatus(), [SupplierInvoice::STATUS_RECEIVED, SupplierInvoice::STATUS_PARTIALLY_PAID, SupplierInvoice::STATUS_PAID], true)) throw new InvalidArgumentException('La facture fournisseur doit être reçue et non annulée avant comptabilisation.');
  $net=0;$tax=0;
  foreach($invoice->getLines() as $line){$net+=self::cents($line->getTotalExcludingTax());$tax+=self::cents($line->getTotalTax());}
  $this->post('supplier_invoice',$invoice->getId(),'AC',$invoice->getInvoiceDate(),'Facture fournisseur '.$invoice->getSupplierInvoiceNumber(),function(AccountingEntry $entry)use($net,$tax,$invoice):void{
   if($net>0)$this->line($entry,'607','Achats - '.$invoice->getSupplierInvoiceNumber(),$net,0);
   if($tax>0)$this->line($entry,'44566','TVA déductible - '.$invoice->getSupplierInvoiceNumber(),$tax,0);
   $this->line($entry,'401','Fournisseur - '.$invoice->getSupplierInvoiceNumber(),0,self::cents($invoice->getTotalIncludingTax()));
  });
 }

 public function postSalesPayment(SalesPayment $payment): void
 {
  if($payment->getId()===null)throw new InvalidArgumentException('Le règlement client doit être enregistré avant comptabilisation.');
  $invoice=$payment->getInvoice();[$journal,$account,$methodLabel]=$this->paymentDestination($payment->getMethod(),true);
  $this->post('sales_payment',$payment->getId(),$journal,$payment->getPaidAt(),'Règlement client '.$invoice->getNumber(),function(AccountingEntry $entry)use($payment,$invoice,$account,$methodLabel):void{
   $amount=self::cents($payment->getAmount());
   $this->line($entry,$account,'Encaissement '.$methodLabel.' client '.$invoice->getNumber(),$amount,0);
   $this->line($entry,'411','Client '.$invoice->getNumber(),0,$amount);
  });
 }

 public function postSupplierPayment(SupplierPayment $payment): void
 {
  if($payment->getId()===null)throw new InvalidArgumentException('Le règlement fournisseur doit être enregistré avant comptabilisation.');
  $invoice=$payment->getInvoice();[$journal,$account,$methodLabel]=$this->paymentDestination($payment->getMethod(),false);
  $this->post('supplier_payment',$payment->getId(),$journal,$payment->getPaidAt(),'Règlement fournisseur '.$invoice->getSupplierInvoiceNumber(),function(AccountingEntry $entry)use($payment,$invoice,$account,$methodLabel):void{
   $amount=self::cents($payment->getAmount());
   $this->line($entry,'401','Fournisseur '.$invoice->getSupplierInvoiceNumber(),$amount,0);
   $this->line($entry,$account,'Décaissement '.$methodLabel.' fournisseur '.$invoice->getSupplierInvoiceNumber(),0,$amount);
  });
 }

 private function post(string $sourceType,int $sourceId,string $journalCode,DateTimeImmutable $date,string $label,callable $build):void
 {
  if(!$this->em->getConnection()->isTransactionActive())throw new \LogicException('La comptabilisation automatique doit être appelée dans la transaction métier source.');
  $repo=$this->em->getRepository(AccountingEntry::class);
  if($repo->findOneBy(['sourceType'=>$sourceType,'sourceId'=>$sourceId]) instanceof AccountingEntry)return;
  $year=$this->em->createQueryBuilder()->select('y')->from(AccountingFiscalYear::class,'y')->where('y.startsOn <= :date')->andWhere('y.endsOn >= :date')->andWhere('y.closed = false')->setParameter('date',$date)->getQuery()->getOneOrNullResult();
  if(!$year instanceof AccountingFiscalYear)throw new InvalidArgumentException('Aucun exercice comptable ouvert pour la date '.$date->format('Y-m-d').'.');
  $journal=$this->em->getRepository(AccountingJournal::class)->findOneBy(['code'=>$journalCode]);
  if(!$journal instanceof AccountingJournal)throw new InvalidArgumentException('Journal comptable '.$journalCode.' absent : vérifiez le paramétrage.');
  $this->em->lock($year,LockMode::PESSIMISTIC_WRITE);$this->em->refresh($year);
  $this->em->lock($journal,LockMode::PESSIMISTIC_WRITE);$this->em->refresh($journal);
  if($year->isClosed()||!$year->contains($date)||!$journal->isActive())throw new InvalidArgumentException('Exercice ou journal comptable fermé.');
  if($repo->findOneBy(['sourceType'=>$sourceType,'sourceId'=>$sourceId]) instanceof AccountingEntry)return;
  $entry=new AccountingEntry($journal,$year,$this->numbers->next($journal,$year),$date,$label);
  $entry->setSource($sourceType,$sourceId);
  $build($entry);
  $entry->validateForPosting()->post();
  $this->em->persist($entry);$this->em->flush();
 }

 private function paymentDestination(string $method,bool $incoming):array
 {
  return match($method){
   'cash'=>['CA','530','espèces'],
   'check'=>$incoming?['BQ','5112','chèque']:['BQ','512','chèque'],
   'card'=>$incoming?['BQ','5115','carte']:['BQ','512','carte'],
   'bank_transfer'=>['BQ','512','virement'],
   'other'=>['OD','471','à affecter'],
   default=>throw new InvalidArgumentException('Moyen de règlement non pris en charge en comptabilité.')
  };
 }

 private function line(AccountingEntry $entry,string $accountNumber,string $label,int $debitCents,int $creditCents):void
 {
  if($debitCents===0&&$creditCents===0)return;
  $account=$this->em->getRepository(AccountingAccount::class)->findOneBy(['number'=>$accountNumber]);
  if(!$account instanceof AccountingAccount||!$account->isActive())throw new InvalidArgumentException('Compte '.$accountNumber.' absent ou inactif : vérifiez le plan comptable.');
  $entry->addLine(new AccountingEntryLine($account,$label,self::money($debitCents),self::money($creditCents),$entry->getLines()->count()+1));
 }

 private static function cents(float|string $amount):int{return (int)round((float)$amount*100,0,PHP_ROUND_HALF_UP);}
 private static function money(int $cents):string{return intdiv($cents,100).'.'.str_pad((string)abs($cents%100),2,'0',STR_PAD_LEFT);}
}
