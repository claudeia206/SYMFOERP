<?php
declare(strict_types=1);
namespace App\Controller;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
final class FinancialReportController extends AbstractController
{
    #[Route('/reports/finance',name:'app_financial_report',methods:['GET'])]
    public function index(Connection $connection): Response
    {
        $sales=$connection->fetchAllAssociative(<<<'SQL'
            SELECT i.id,i.number,i.status,i.invoice_date,i.due_date,t.name AS counterparty,
                   COALESCE(amounts.total,0) AS total,COALESCE(payments.paid,0) AS paid
            FROM app_sales_invoice i
            INNER JOIN app_third_party t ON t.id=i.third_party_id
            LEFT JOIN (
                SELECT invoice_id,SUM(ROUND(quantity*unit_price,2)+ROUND(ROUND(quantity*unit_price,2)*vat_rate/100,2)) AS total
                FROM app_sales_invoice_line GROUP BY invoice_id
            ) amounts ON amounts.invoice_id=i.id
            LEFT JOIN (
                SELECT invoice_id,SUM(amount) AS paid
                FROM app_sales_payment GROUP BY invoice_id
            ) payments ON payments.invoice_id=i.id
            WHERE i.status NOT IN ('draft','cancelled')
            ORDER BY i.due_date ASC,i.id ASC
            SQL);
        $purchases=$connection->fetchAllAssociative(<<<'SQL'
            SELECT i.id,i.supplier_invoice_number AS number,i.status,i.invoice_date,i.due_date,t.name AS counterparty,
                   COALESCE(amounts.total,0) AS total,COALESCE(payments.paid,0) AS paid
            FROM app_supplier_invoice i
            INNER JOIN app_third_party t ON t.id=i.supplier_id
            LEFT JOIN (
                SELECT invoice_id,SUM(ROUND(quantity*unit_price*(1+vat_rate/100),2)) AS total
                FROM app_supplier_invoice_line GROUP BY invoice_id
            ) amounts ON amounts.invoice_id=i.id
            LEFT JOIN (
                SELECT invoice_id,SUM(amount) AS paid
                FROM app_supplier_payment GROUP BY invoice_id
            ) payments ON payments.invoice_id=i.id
            WHERE i.status NOT IN ('draft','cancelled')
            ORDER BY i.due_date ASC,i.id ASC
            SQL);
        $today=new DateTimeImmutable('today');
        $salesOutstanding=0.0; $salesOverdue=0; $supplierOutstanding=0.0; $supplierOverdue=0;
        $overdueReceivables=[]; $overduePayables=[];
        foreach($sales as &$invoice) {
            $invoice['total']=(float)$invoice['total']; $invoice['paid']=(float)$invoice['paid'];
            $invoice['remaining']=max(0.0,round($invoice['total']-$invoice['paid'],2));
            $salesOutstanding+=$invoice['remaining'];
            $invoice['overdue']=$invoice['remaining']>0.001 && new DateTimeImmutable($invoice['due_date'])<$today;
            if($invoice['overdue']) { ++$salesOverdue; $overdueReceivables[]=$invoice; }
        }
        unset($invoice);
        foreach($purchases as &$invoice) {
            $invoice['total']=(float)$invoice['total']; $invoice['paid']=(float)$invoice['paid'];
            $invoice['remaining']=max(0.0,round($invoice['total']-$invoice['paid'],2));
            $supplierOutstanding+=$invoice['remaining'];
            $invoice['overdue']=$invoice['remaining']>0.001 && new DateTimeImmutable($invoice['due_date'])<$today;
            if($invoice['overdue']) { ++$supplierOverdue; $overduePayables[]=$invoice; }
        }
        unset($invoice);
        $monthStart=$today->modify('first day of this month')->format('Y-m-d');
        $monthEnd=$today->modify('first day of next month')->format('Y-m-d');
        $collected=(float)$connection->fetchOne('SELECT COALESCE(SUM(amount),0) FROM app_sales_payment WHERE paid_at>=? AND paid_at<?',[$monthStart,$monthEnd]);
        $disbursed=(float)$connection->fetchOne('SELECT COALESCE(SUM(amount),0) FROM app_supplier_payment WHERE paid_at>=? AND paid_at<?',[$monthStart,$monthEnd]);
        return $this->render('financial_report/index.html.twig',[
            'sales_outstanding'=>round($salesOutstanding,2),
            'sales_overdue_count'=>$salesOverdue,
            'supplier_outstanding'=>round($supplierOutstanding,2),
            'supplier_overdue_count'=>$supplierOverdue,
            'collected_this_month'=>round($collected,2),
            'disbursed_this_month'=>round($disbursed,2),
            'overdue_receivables'=>array_slice($overdueReceivables,0,10),
            'overdue_payables'=>array_slice($overduePayables,0,10),
        ]);
    }
}
