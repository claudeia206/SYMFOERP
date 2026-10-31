<?php
declare(strict_types=1);
namespace App\Controller;
use App\Service\AccountingAmount;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
final class CashFlowForecastController extends AbstractController
{
    #[Route('/reports/cash-flow',name:'app_cash_flow_forecast',methods:['GET'])]
    public function index(Connection $db):Response
    {
        $today=new DateTimeImmutable('today');
        $firstMonday=$today->modify('monday this week');
        $weeks=[];
        for($i=0;$i<13;$i++){
            $start=$firstMonday->modify("+{$i} weeks");
            $weeks[$start->format('Y-m-d')]=['start'=>$start,'end'=>$start->modify('+6 days'),'inflows'=>0,'outflows'=>0,'overdue_inflows'=>0,'overdue_outflows'=>0];
        }
        $sales=$db->fetchAllAssociative(<<<'SQL'
SELECT i.due_date,GREATEST(0,COALESCE(a.total,0)-COALESCE(c.total,0)-COALESCE(p.paid,0)) AS remaining
FROM app_sales_invoice i
LEFT JOIN (SELECT invoice_id,SUM(ROUND(quantity*unit_price,2)+ROUND(ROUND(quantity*unit_price,2)*vat_rate/100,2)) total FROM app_sales_invoice_line GROUP BY invoice_id) a ON a.invoice_id=i.id
LEFT JOIN (SELECT n.invoice_id,SUM(ROUND(l.quantity*l.unit_price,2)+ROUND(ROUND(l.quantity*l.unit_price,2)*l.vat_rate/100,2)) total FROM app_sales_credit_note n INNER JOIN app_sales_credit_note_line l ON l.credit_note_id=n.id GROUP BY n.invoice_id) c ON c.invoice_id=i.id
LEFT JOIN (SELECT invoice_id,SUM(amount) paid FROM app_sales_payment GROUP BY invoice_id) p ON p.invoice_id=i.id
WHERE i.status NOT IN ('draft','cancelled')
SQL);
        $purchases=$db->fetchAllAssociative(<<<'SQL'
SELECT i.due_date,GREATEST(0,COALESCE(a.total,0)-COALESCE(p.paid,0)) AS remaining
FROM app_supplier_invoice i
LEFT JOIN (SELECT invoice_id,SUM(ROUND(quantity*unit_price,2)+ROUND(ROUND(quantity*unit_price,2)*vat_rate/100,2)) total FROM app_supplier_invoice_line GROUP BY invoice_id) a ON a.invoice_id=i.id
LEFT JOIN (SELECT invoice_id,SUM(amount) paid FROM app_supplier_payment GROUP BY invoice_id) p ON p.invoice_id=i.id
WHERE i.status NOT IN ('draft','cancelled')
SQL);
        $overdueIn=0;$overdueOut=0;
        foreach([['rows'=>$sales,'direction'=>'in'],['rows'=>$purchases,'direction'=>'out']] as $group){
            foreach($group['rows'] as $row){
                $amount=AccountingAmount::toCents((string)$row['remaining']);if($amount<=0)continue;
                $due=new DateTimeImmutable($row['due_date']);
                $isOverdue=$due<$today;
                if($isOverdue){$due=$today;if($group['direction']==='in')$overdueIn=AccountingAmount::addSignedCents($overdueIn,$amount);else $overdueOut=AccountingAmount::addSignedCents($overdueOut,$amount);}
                $weekStart=$due->modify('monday this week')->format('Y-m-d');
                if(!isset($weeks[$weekStart]))continue;
                $key=$group['direction']==='in'?'inflows':'outflows';
                $weeks[$weekStart][$key]=AccountingAmount::addSignedCents($weeks[$weekStart][$key],$amount);
                if($isOverdue){$overdueKey=$group['direction']==='in'?'overdue_inflows':'overdue_outflows';$weeks[$weekStart][$overdueKey]=AccountingAmount::addSignedCents($weeks[$weekStart][$overdueKey],$amount);}
            }
        }
        $cumulative=0;$forecast=[];$totalInflows=0;$totalOutflows=0;
        foreach($weeks as $week){
            $in=$week['inflows'];$out=$week['outflows'];$net=AccountingAmount::addSignedCents($in,-$out);$cumulative=AccountingAmount::addSignedCents($cumulative,$net);
            $totalInflows=AccountingAmount::addSignedCents($totalInflows,$in);$totalOutflows=AccountingAmount::addSignedCents($totalOutflows,$out);
            $forecast[]=['start'=>$week['start'],'end'=>$week['end'],
                'inflows'=>AccountingAmount::formatFrenchCents($in),'outflows'=>AccountingAmount::formatFrenchCents($out),
                'net'=>AccountingAmount::formatFrenchCents($net),'cumulative_net'=>AccountingAmount::formatFrenchCents($cumulative),
                'overdue_inflows'=>AccountingAmount::formatFrenchCents($week['overdue_inflows']),
                'overdue_outflows'=>AccountingAmount::formatFrenchCents($week['overdue_outflows']),
                'has_overdue_inflows'=>$week['overdue_inflows']>0,
                'has_overdue_outflows'=>$week['overdue_outflows']>0];
        }
        return $this->render('cash_flow_forecast/index.html.twig',[
            'forecast'=>$forecast,'overdue_inflows'=>AccountingAmount::formatFrenchCents($overdueIn),
            'overdue_outflows'=>AccountingAmount::formatFrenchCents($overdueOut),
            'total_inflows'=>AccountingAmount::formatFrenchCents($totalInflows),
            'total_outflows'=>AccountingAmount::formatFrenchCents($totalOutflows),
            'net_forecast'=>AccountingAmount::formatFrenchCents(AccountingAmount::addSignedCents($totalInflows,-$totalOutflows)),
        ]);
    }
}
