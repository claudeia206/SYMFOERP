<?php
declare(strict_types=1);
namespace App\Controller;
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
            $weeks[$start->format('Y-m-d')]=['start'=>$start,'end'=>$start->modify('+6 days'),'inflows'=>0.0,'outflows'=>0.0,'overdue_inflows'=>0.0,'overdue_outflows'=>0.0];
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
        $overdueIn=0.0;$overdueOut=0.0;
        foreach([['rows'=>$sales,'direction'=>'in'],['rows'=>$purchases,'direction'=>'out']] as $group){
            foreach($group['rows'] as $row){
                $amount=round((float)$row['remaining'],2);if($amount<=0.001)continue;
                $due=new DateTimeImmutable($row['due_date']);
                $isOverdue=$due<$today;
                if($isOverdue){$due=$today;if($group['direction']==='in')$overdueIn+=$amount;else $overdueOut+=$amount;}
                $weekStart=$due->modify('monday this week')->format('Y-m-d');
                if(!isset($weeks[$weekStart]))continue;
                $key=$group['direction']==='in'?'inflows':'outflows';
                $weeks[$weekStart][$key]+=$amount;
                if($isOverdue){$overdueKey=$group['direction']==='in'?'overdue_inflows':'overdue_outflows';$weeks[$weekStart][$overdueKey]+=$amount;}
            }
        }
        $cumulative=0.0;$forecast=[];
        foreach($weeks as $week){
            $in=round($week['inflows'],2);$out=round($week['outflows'],2);$net=round($in-$out,2);$cumulative=round($cumulative+$net,2);
            $forecast[]=['start'=>$week['start'],'end'=>$week['end'],'inflows'=>$in,'outflows'=>$out,'net'=>$net,'cumulative_net'=>$cumulative,'overdue_inflows'=>round($week['overdue_inflows'],2),'overdue_outflows'=>round($week['overdue_outflows'],2)];
        }
        return $this->render('cash_flow_forecast/index.html.twig',[
            'forecast'=>$forecast,'overdue_inflows'=>round($overdueIn,2),'overdue_outflows'=>round($overdueOut,2),
            'total_inflows'=>round(array_sum(array_column($forecast,'inflows')),2),
            'total_outflows'=>round(array_sum(array_column($forecast,'outflows')),2),
        ]);
    }
}
