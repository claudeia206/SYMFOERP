<?php
declare(strict_types=1);
namespace App\Controller;
use App\Entity\AccountingEntry;
use App\Entity\AccountingEntryLine;
use App\Entity\AccountingAccount;
use App\Entity\AccountingJournal;
use App\Entity\AccountingFiscalYear;
use App\Service\AccountingEntryNumberGenerator;
use App\Service\AccountingPostingService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\LockMode;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
#[Route('/accounting')]
final class AccountingController extends AbstractController
{
 #[Route('',name:'app_accounting',methods:['GET'])]
 public function index(EntityManagerInterface $em):Response{
  $entries=$em->getRepository(AccountingEntry::class)->findBy([],['entryDate'=>'DESC','id'=>'DESC'],50);
  $accounts=$em->getRepository(AccountingAccount::class)->findBy(['active'=>true],['number'=>'ASC']);
  $journals=$em->getRepository(AccountingJournal::class)->findBy(['active'=>true],['code'=>'ASC']);
  $years=$em->getRepository(AccountingFiscalYear::class)->findBy([],['startsOn'=>'DESC']);
  $totals=['debit'=>0,'credit'=>0];
  foreach($entries as $entry){if($entry->getStatus()==='posted'){$t=$entry->totals();$totals['debit']+=$t['debit'];$totals['credit']+=$t['credit'];}}
  return $this->render('accounting/index.html.twig',['entries'=>$entries,'accounts'=>$accounts,'journals'=>$journals,'years'=>$years,'totals'=>$totals]);
 }

 #[Route('/entries/new',name:'app_accounting_entry_new',methods:['GET','POST'])]
 public function newEntry(Request $request,EntityManagerInterface $em,AccountingEntryNumberGenerator $numbers,AccountingPostingService $posting):Response{
  $accounts=$em->getRepository(AccountingAccount::class)->findBy(['active'=>true],['number'=>'ASC']);
  $journals=$em->getRepository(AccountingJournal::class)->findBy(['active'=>true],['code'=>'ASC']);
  $years=$em->getRepository(AccountingFiscalYear::class)->findBy(['closed'=>false],['startsOn'=>'DESC']);
  if($request->isMethod('GET')) return $this->render('accounting/entry_new.html.twig',['accounts'=>$accounts,'journals'=>$journals,'years'=>$years]);
  if(!$this->isCsrfTokenValid('accounting_entry_new',$request->request->getString('_token'))){$this->addFlash('error','Jeton de sécurité invalide.');return $this->redirectToRoute('app_accounting_entry_new');}
  try{
   $journal=$em->getRepository(AccountingJournal::class)->find($request->request->getInt('journal_id'));
   $year=$em->getRepository(AccountingFiscalYear::class)->find($request->request->getInt('fiscal_year_id'));
   $dateText=$request->request->getString('entry_date');
   if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$dateText))throw new InvalidArgumentException('Date invalide (format AAAA-MM-JJ attendu).');
   $date=DateTimeImmutable::createFromFormat('!Y-m-d',$dateText);
   $dateErrors=DateTimeImmutable::getLastErrors();
   if(!$date||($dateErrors!==false&&($dateErrors['warning_count']>0||$dateErrors['error_count']>0))||$date->format('Y-m-d')!==$dateText)throw new InvalidArgumentException('Date comptable invalide.');
   $label=trim($request->request->getString('label'));
   if(!$journal||!$year||!$journal->isActive()||$year->isClosed()||!$year->contains($date)||$label==='')throw new InvalidArgumentException('Journal, exercice, date ou libellé invalide.');
   $rows=$request->request->all('lines');
   if(count($rows)<2||count($rows)>100)throw new InvalidArgumentException('Une écriture doit contenir entre 2 et 100 lignes.');
   $em->wrapInTransaction(function()use($em,$journal,$year,$date,$label,$rows,$numbers,$posting):void{
    $em->lock($journal,LockMode::PESSIMISTIC_WRITE);$em->refresh($journal);
    $em->lock($year,LockMode::PESSIMISTIC_WRITE);$em->refresh($year);
    if(!$journal->isActive()||$year->isClosed()||!$year->contains($date))throw new InvalidArgumentException('Le journal ou l’exercice a été fermé pendant la saisie.');
    $entry=new AccountingEntry($journal,$year,$numbers->next($journal,$year),$date,$label);
    $position=1;
    foreach($rows as $row){
     if(!is_array($row))throw new InvalidArgumentException('Ligne comptable mal formée.');
     $account=$em->getRepository(AccountingAccount::class)->find((int)($row['account_id']??0));
     if(!$account)throw new InvalidArgumentException('Compte comptable introuvable.');
     $debit=$this->normalizeAmount((string)($row['debit']??'0'));
     $credit=$this->normalizeAmount((string)($row['credit']??'0'));
     $lineLabel=trim((string)($row['label']??''));
     if($lineLabel==='')$lineLabel=$label;
     $entry->addLine(new AccountingEntryLine($account,$lineLabel,$debit,$credit,$position++));
    }
    $entry->validateForPosting();
    $em->persist($entry);$em->flush();
    $posting->post($entry);
   });
   $this->addFlash('success','Écriture comptabilisée.');
   return $this->redirectToRoute('app_accounting');
  }catch(\Throwable $e){$this->addFlash('error',$e instanceof InvalidArgumentException?$e->getMessage():'Impossible de comptabiliser cette écriture. Vérifiez les données et réessayez.');return $this->redirectToRoute('app_accounting_entry_new');}
 }
 private function normalizeAmount(string $amount):string{
  $amount=trim($amount);
  if(!preg_match('/^(?:0|[1-9][0-9]{0,11})(?:[.,][0-9]{1,2})?$/D',$amount))throw new InvalidArgumentException('Montant invalide : utilisez un nombre positif avec deux décimales maximum.');
  return str_replace(',','.',$amount);
 }
}