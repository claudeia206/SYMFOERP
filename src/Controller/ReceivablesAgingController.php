<?php
declare(strict_types=1);
namespace App\Controller;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
final class ReceivablesAgingController extends AbstractController
{
    private const BUCKETS=[
        '1_30'=>['label'=>'1 à 30 jours','min'=>1,'max'=>30],
        '31_60'=>['label'=>'31 à 60 jours','min'=>31,'max'=>60],
        '61_90'=>['label'=>'61 à 90 jours','min'=>61,'max'=>90],
        'over_90'=>['label'=>'Plus de 90 jours','min'=>91,'max'=>null],
    ];
    #[Route('/reports/aging',name:'app_receivables_aging',methods:['GET'])]
    public function index(Connection $db):Response
    {
        $sales=$db->fetchAllAssociative(<<<'SQL'
SELECT i.id,i.number,i.due_date,t.name AS counterparty,
 GREATEST(0,COALESCE(a.total,0)-COALESCE(c.total,0)-COALESCE(p.paid,0)) AS remaining,
 DATEDIFF(CURRENT_DATE,i.due_date) AS days_overdue
FROM app_sales_invoice i INNER JOIN app_third_party t ON t.id=i.third_party_id
LEFT JOIN (SELECT invoice_id,SUM(ROUND(quantity*unit_price,2)+ROUND(ROUND(quantity*unit_price,2)*vat_rate/100,2)) total FROM app_sales_invoice_line GROUP BY invoice_id) a ON a.invoice_id=i.id
LEFT JOIN (SELECT n.invoice_id,SUM(ROUND(l.quantity*l.unit_price,2)+ROUND(ROUND(l.quantity*l.unit_price,2)*l.vat_rate/100,2)) total FROM app_sales_credit_note n INNER JOIN app_sales_credit_note_line l ON l.credit_note_id=n.id GROUP BY n.invoice_id) c ON c.invoice_id=i.id
LEFT JOIN (SELECT invoice_id,SUM(amount) paid FROM app_sales_payment GROUP BY invoice_id) p ON p.invoice_id=i.id
WHERE i.status NOT IN ('draft','cancelled') AND i.due_date<CURRENT_DATE
HAVING remaining>0.001
ORDER BY days_overdue DESC,i.id ASC
SQL);
        $purchases=$db->fetchAllAssociative(<<<'SQL'
SELECT i.id,i.supplier_invoice_number AS number,i.due_date,t.name AS counterparty,
 GREATEST(0,COALESCE(a.total,0)-COALESCE(p.paid,0)) AS remaining,
 DATEDIFF(CURRENT_DATE,i.due_date) AS days_overdue
FROM app_supplier_invoice i INNER JOIN app_third_party t ON t.id=i.supplier_id
LEFT JOIN (SELECT invoice_id,SUM(ROUND(quantity*unit_price*(1+vat_rate/100),2)) total FROM app_supplier_invoice_line GROUP BY invoice_id) a ON a.invoice_id=i.id
LEFT JOIN (SELECT invoice_id,SUM(amount) paid FROM app_supplier_payment GROUP BY invoice_id) p ON p.invoice_id=i.id
WHERE i.status NOT IN ('draft','cancelled') AND i.due_date<CURRENT_DATE
HAVING remaining>0.001
ORDER BY days_overdue DESC,i.id ASC
SQL);
        $salesBuckets=$this->bucket($sales);$purchaseBuckets=$this->bucket($purchases);
        return $this->render('receivables_aging/index.html.twig',[
            'sales'=>$sales,'purchases'=>$purchases,
            'sales_buckets'=>$salesBuckets,'purchase_buckets'=>$purchaseBuckets,
            'sales_total'=>round(array_sum(array_column($salesBuckets,'total')),2),
            'purchase_total'=>round(array_sum(array_column($purchaseBuckets,'total')),2),
        ]);
    }
    private function bucket(array $rows):array
    {
        $result=[];foreach(self::BUCKETS as $key=>$bucket)$result[$key]=['label'=>$bucket['label'],'count'=>0,'total'=>0.0];
        foreach($rows as &$row){
            $days=(int)$row['days_overdue'];$key=$days<=30?'1_30':($days<=60?'31_60':($days<=90?'61_90':'over_90'));
            $row['remaining']=round((float)$row['remaining'],2);$row['bucket']=$key;
            ++$result[$key]['count'];$result[$key]['total']+=$row['remaining'];
        }unset($row);
        foreach($result as &$bucket)$bucket['total']=round($bucket['total'],2);unset($bucket);
        return $result;
    }
}
