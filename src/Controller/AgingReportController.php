<?php
declare(strict_types=1);
namespace App\Controller;
use App\Service\AccountingAmount;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
#[Route('/accounting/aging')]
final class AgingReportController extends AbstractController
{
    #[Route('', name: 'app_accounting_aging', methods: ['GET'])]
    public function index(Connection $db): Response
    {
        $today=(new DateTimeImmutable('today'))->format('Y-m-d');
        $receivables=$db->fetchAllAssociative(<<<'SQL'
SELECT i.id,i.number AS reference,i.invoice_date,i.due_date,t.name AS third_party,
       COALESCE(invoice_lines.total,0) AS total,COALESCE(payments.paid,0) AS paid,
       COALESCE(credits.credited,0) AS credited,
       GREATEST(0,ROUND(COALESCE(invoice_lines.total,0)-COALESCE(payments.paid,0)-COALESCE(credits.credited,0),2)) AS remaining
FROM app_sales_invoice i
INNER JOIN app_third_party t ON t.id=i.third_party_id
LEFT JOIN (SELECT invoice_id,SUM(ROUND(quantity*unit_price,2)+ROUND(ROUND(quantity*unit_price,2)*vat_rate/100,2)) AS total FROM app_sales_invoice_line GROUP BY invoice_id) invoice_lines ON invoice_lines.invoice_id=i.id
LEFT JOIN (SELECT invoice_id,SUM(amount) AS paid FROM app_sales_payment GROUP BY invoice_id) payments ON payments.invoice_id=i.id
LEFT JOIN (SELECT n.invoice_id,SUM(cl.amount) AS credited FROM app_sales_credit_note n INNER JOIN (SELECT credit_note_id,SUM(ROUND(quantity*unit_price,2)+ROUND(ROUND(quantity*unit_price,2)*vat_rate/100,2)) AS amount FROM app_sales_credit_note_line GROUP BY credit_note_id) cl ON cl.credit_note_id=n.id GROUP BY n.invoice_id) credits ON credits.invoice_id=i.id
WHERE i.number IS NOT NULL AND i.status IN ('issued','partially_paid','overdue')
HAVING remaining>0
ORDER BY i.due_date,i.id
SQL);
        $payables=$db->fetchAllAssociative(<<<'SQL'
SELECT i.id,i.supplier_invoice_number AS reference,i.invoice_date,i.due_date,t.name AS third_party,
       COALESCE(invoice_lines.total,0) AS total,COALESCE(payments.paid,0) AS paid,
       GREATEST(0,ROUND(COALESCE(invoice_lines.total,0)-COALESCE(payments.paid,0),2)) AS remaining
FROM app_supplier_invoice i
INNER JOIN app_third_party t ON t.id=i.supplier_id
LEFT JOIN (SELECT invoice_id,SUM(ROUND(quantity*unit_price,2)+ROUND(ROUND(quantity*unit_price,2)*vat_rate/100,2)) AS total FROM app_supplier_invoice_line GROUP BY invoice_id) invoice_lines ON invoice_lines.invoice_id=i.id
LEFT JOIN (SELECT invoice_id,SUM(amount) AS paid FROM app_supplier_payment GROUP BY invoice_id) payments ON payments.invoice_id=i.id
WHERE i.status IN ('received','partially_paid','overdue')
HAVING remaining>0
ORDER BY i.due_date,i.id
SQL);
        $receivableTotals=$this->summarize($receivables,$today);
        $payableTotals=$this->summarize($payables,$today);
        return $this->render('accounting/aging.html.twig',[
            'today'=>$today,'receivables'=>$receivables,'payables'=>$payables,
            'receivable_totals'=>$receivableTotals,'payable_totals'=>$payableTotals,
        ]);
    }
    private function summarize(array &$rows,string $today):array
    {
        $totals=['current'=>0,'d1_30'=>0,'d31_60'=>0,'d61_90'=>0,'d90'=>0,'total'=>0];
        foreach($rows as &$row){
            $days=(int)(new DateTimeImmutable($row['due_date']))->diff(new DateTimeImmutable($today))->format('%r%a');
            $row['days_overdue']=max(0,$days);
            $bucket=$days<=0?'current':($days<=30?'d1_30':($days<=60?'d31_60':($days<=90?'d61_90':'d90')));
            $row['bucket']=$bucket;
            $remaining=AccountingAmount::toCents((string)$row['remaining']);
            $row['remaining']=AccountingAmount::formatCents($remaining);
            $totals[$bucket]=AccountingAmount::addSignedCents($totals[$bucket],$remaining);$totals['total']=AccountingAmount::addSignedCents($totals['total'],$remaining);
        }
        unset($row);
        foreach($totals as &$amount)$amount=AccountingAmount::formatCents($amount);
        unset($amount);
        return $totals;
    }
}