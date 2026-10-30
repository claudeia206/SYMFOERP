<?php
declare(strict_types=1);
namespace App\Controller;
use App\Entity\AccountingEntry;
use App\Entity\AccountingAccount;
use App\Entity\AccountingJournal;
use App\Entity\AccountingFiscalYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
}