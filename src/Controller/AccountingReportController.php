<?php
declare(strict_types=1);
namespace App\Controller;
use App\Entity\AccountingAccount;
use App\Entity\AccountingEntryLine;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
#[Route('/accounting')]
final class AccountingReportController extends AbstractController
{
 #[Route('/trial-balance',name:'app_accounting_trial_balance',methods:['GET'])]
 public function trialBalance(Request $request,EntityManagerInterface $em):Response{
  [$from,$to]=$this->period($request);
  $rows=$em->createQueryBuilder()->select(
    'a.id AS accountId','a.number AS accountNumber','a.label AS accountLabel',
    'SUM(CASE WHEN e.entryDate < :from THEN l.debit ELSE 0 END) AS openingDebit',
    'SUM(CASE WHEN e.entryDate < :from THEN l.credit ELSE 0 END) AS openingCredit',
    'SUM(CASE WHEN e.entryDate >= :from AND e.entryDate <= :to THEN l.debit ELSE 0 END) AS debit',
    'SUM(CASE WHEN e.entryDate >= :from AND e.entryDate <= :to THEN l.credit ELSE 0 END) AS credit'
   )
   ->from(AccountingEntryLine::class,'l')->join('l.entry','e')->join('l.account','a')
   ->where('e.status = :status')->andWhere('e.entryDate <= :to')
   ->setParameter('status','posted')->setParameter('from',$from)->setParameter('to',$to)
   ->groupBy('a.id')->addGroupBy('a.number')->addGroupBy('a.label')->orderBy('a.number','ASC')->getQuery()->getArrayResult();
  $debitTotal=0;$creditTotal=0;$openingBalanceTotal=0;$closingBalanceTotal=0;
  foreach($rows as &$row){
   $openingDebit=$this->toCents((string)$row['openingDebit']);$openingCredit=$this->toCents((string)$row['openingCredit']);
   $row['openingBalanceCents']=$openingDebit-$openingCredit;
   $row['debitCents']=$this->toCents((string)$row['debit']);$row['creditCents']=$this->toCents((string)$row['credit']);
   $row['balanceCents']=$row['openingBalanceCents']+$row['debitCents']-$row['creditCents'];
   $debitTotal+=$row['debitCents'];$creditTotal+=$row['creditCents'];$openingBalanceTotal+=$row['openingBalanceCents'];$closingBalanceTotal+=$row['balanceCents'];
  }unset($row);
  return $this->render('accounting/trial_balance.html.twig',['rows'=>$rows,'from'=>$from,'to'=>$to,'debitTotal'=>$debitTotal,'creditTotal'=>$creditTotal,'openingBalanceTotal'=>$openingBalanceTotal,'closingBalanceTotal'=>$closingBalanceTotal]);
 }
 #[Route('/ledger/{id}',name:'app_accounting_ledger',requirements:['id'=>'\d+'],methods:['GET'])]
 public function ledger(int $id,Request $request,EntityManagerInterface $em):Response{
  $account=$em->getRepository(AccountingAccount::class)->find($id);
  if(!$account)throw $this->createNotFoundException('Compte comptable introuvable.');
  [$from,$to]=$this->period($request);
  $lines=$em->createQueryBuilder()->select('l','e','j')
   ->from(AccountingEntryLine::class,'l')->join('l.entry','e')->join('e.journal','j')
   ->where('l.account = :account')->andWhere('e.status = :status')->andWhere('e.entryDate <= :to')
   ->setParameter('account',$account)->setParameter('status','posted')->setParameter('to',$to)
   ->orderBy('e.entryDate','ASC')->addOrderBy('e.id','ASC')->addOrderBy('l.position','ASC')->getQuery()->getResult();
  $balance=0;$totalDebit=0;$totalCredit=0;$openingBalance=0;$movements=[];
  foreach($lines as $line){
   $entryDate=$line->getEntry()->getEntryDate();$debit=$line->getDebitCents();$credit=$line->getCreditCents();
   if($entryDate<$from){$balance+=$debit-$credit;$openingBalance+=$debit-$credit;continue;}
   $balance+=$debit-$credit;$totalDebit+=$debit;$totalCredit+=$credit;$movements[]=['line'=>$line,'balance'=>$balance];
  }
  return $this->render('accounting/ledger.html.twig',['account'=>$account,'movements'=>$movements,'from'=>$from,'to'=>$to,'openingBalance'=>$openingBalance,'totalDebit'=>$totalDebit,'totalCredit'=>$totalCredit,'balance'=>$balance]);
 }
 private function period(Request $request):array{
  $from=$this->parseDate($request->query->get('from'),'2000-01-01');
  $to=$this->parseDate($request->query->get('to'),(new DateTimeImmutable())->format('Y-m-d'));
  if($to<$from)throw new BadRequestHttpException('La date de fin doit être postérieure ou égale à la date de début.');
  return [$from,$to];
 }
 private function parseDate(mixed $value,string $fallback):DateTimeImmutable{
  $value=is_string($value)&&$value!==''?$value:$fallback;
  if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value))throw new BadRequestHttpException('Date de filtre invalide.');
  $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);$errors=DateTimeImmutable::getLastErrors();
  if(!$date||($errors!==false&&($errors['warning_count']>0||$errors['error_count']>0))||$date->format('Y-m-d')!==$value)throw new BadRequestHttpException('Date de filtre invalide.');
  return $date;
 }
 private function toCents(string $amount):int{
  if(!preg_match('/^([0-9]+)(?:\.([0-9]{1,2}))?$/D',$amount,$m))throw new InvalidArgumentException('Montant agrégé inattendu.');
  return ((int)$m[1]*100)+(int)str_pad($m[2]??'',2,'0');
 }
}