<?php
declare(strict_types=1);
namespace App\Controller;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
#[Route('/accounting/statements')]
final class FinancialStatementsController extends AbstractController
{
    #[Route('',name:'app_financial_statements',methods:['GET'])]
    public function index(Connection $db,Request $request):Response
    {
        $from=(string)$request->query->get('from',(new DateTimeImmutable('first day of january this year'))->format('Y-m-d'));
        $to=(string)$request->query->get('to',(new DateTimeImmutable('today'))->format('Y-m-d'));
        foreach([$from,$to] as $date){$d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$d||$d->format('Y-m-d')!==$date)throw $this->createNotFoundException('Période invalide.');}
        if($from>$to)throw $this->createNotFoundException('Période invalide.');
        $rows=$db->fetchAllAssociative(<<<'SQL'
SELECT a.code,a.name,LEFT(a.code,1) AS class,
       ROUND(COALESCE(SUM(l.debit),0),2) AS debit,
       ROUND(COALESCE(SUM(l.credit),0),2) AS credit
FROM app_accounting_account a
LEFT JOIN app_journal_entry_line l ON l.account_id=a.id
LEFT JOIN app_journal_entry e ON e.id=l.entry_id AND e.status='posted' AND e.entry_date BETWEEN ? AND ?
WHERE a.is_active=1 AND a.code REGEXP '^[1-7]'
GROUP BY a.id,a.code,a.name ORDER BY a.code
SQL,[$from,$to]);
        $groups=['assets'=>[],'liabilities'=>[],'equity'=>[],'income'=>[],'expenses'=>[]];
        $totals=['assets'=>0.0,'liabilities'=>0.0,'equity'=>0.0,'income'=>0.0,'expenses'=>0.0];
        foreach($rows as $row){
            $class=$row['class'];$debit=(float)$row['debit'];$credit=(float)$row['credit'];
            $key=match($class){'1'=>'liabilities','2'=>'assets','3'=>'equity','4'=>'income','5'=>'expenses','6'=>'expenses','7'=>'income',default=>null};
            if($key===null)continue;
            $balance=in_array($class,['1','3','4','7'],true)?$credit-$debit:$debit-$credit;
            if(abs($balance)<0.005)continue;
            $groups[$key][]=['code'=>$row['code'],'name'=>$row['name'],'balance'=>round($balance,2)];
            $totals[$key]+= $balance;
        }
        foreach($totals as &$v)$v=round($v,2);unset($v);
        return $this->render('accounting/statements.html.twig',['groups'=>$groups,'totals'=>$totals,'from'=>$from,'to'=>$to,'net_result'=>round($totals['income']-$totals['expenses'],2)]);
    }
}