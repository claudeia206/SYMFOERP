<?php
declare(strict_types=1);
namespace App\Controller;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
final class PaymentReminderController extends AbstractController
{
    #[Route('/sales/payment-reminders',name:'app_payment_reminders',methods:['GET'])]
    public function index(Connection $db):Response
    {
        $invoices=$db->fetchAllAssociative(<<<'SQL'
SELECT i.id,i.number,i.due_date,t.name AS customer_name,t.email,
 GREATEST(0,COALESCE(a.total,0)-COALESCE(c.total,0)-COALESCE(p.paid,0)) AS remaining,
 DATEDIFF(CURRENT_DATE,i.due_date) AS days_overdue,
 COALESCE(r.reminder_count,0) AS reminder_count,
 r.last_reminder
FROM app_sales_invoice i
INNER JOIN app_third_party t ON t.id=i.third_party_id
LEFT JOIN (SELECT invoice_id,SUM(ROUND(quantity*unit_price,2)+ROUND(ROUND(quantity*unit_price,2)*vat_rate/100,2)) total FROM app_sales_invoice_line GROUP BY invoice_id) a ON a.invoice_id=i.id
LEFT JOIN (SELECT n.invoice_id,SUM(ROUND(l.quantity*l.unit_price,2)+ROUND(ROUND(l.quantity*l.unit_price,2)*l.vat_rate/100,2)) total FROM app_sales_credit_note n INNER JOIN app_sales_credit_note_line l ON l.credit_note_id=n.id GROUP BY n.invoice_id) c ON c.invoice_id=i.id
LEFT JOIN (SELECT invoice_id,SUM(amount) paid FROM app_sales_payment GROUP BY invoice_id) p ON p.invoice_id=i.id
LEFT JOIN (SELECT invoice_id,COUNT(*) reminder_count,MAX(sent_at) last_reminder FROM app_customer_payment_reminder GROUP BY invoice_id) r ON r.invoice_id=i.id
WHERE i.status NOT IN ('draft','cancelled') AND i.due_date<CURRENT_DATE
 AND GREATEST(0,COALESCE(a.total,0)-COALESCE(c.total,0)-COALESCE(p.paid,0))>0.001
ORDER BY i.due_date ASC,i.id ASC
SQL);
        $history=$db->fetchAllAssociative(<<<'SQL'
SELECT r.id,r.invoice_id,r.reminder_level,r.channel,r.recipient,r.note,r.sent_at,i.number,t.name AS customer_name
FROM app_customer_payment_reminder r
INNER JOIN app_sales_invoice i ON i.id=r.invoice_id
INNER JOIN app_third_party t ON t.id=i.third_party_id
ORDER BY r.sent_at DESC,r.id DESC LIMIT 100
SQL);
        return $this->render('payment_reminder/index.html.twig',['invoices'=>$invoices,'history'=>$history]);
    }

    #[Route('/sales/invoices/{id}/payment-reminders',name:'app_payment_reminder_record',requirements:['id'=>'\d+'],methods:['POST'])]
    public function record(int $id,Request $request,Connection $db):Response
    {
        if(!$this->isCsrfTokenValid('payment_reminder_'.$id,(string)$request->request->get('_token')))throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        $invoice=$db->fetchAssociative('SELECT i.id,i.status,t.email FROM app_sales_invoice i INNER JOIN app_third_party t ON t.id=i.third_party_id WHERE i.id=?',[$id]);
        if(!$invoice || in_array($invoice['status'],['draft','cancelled'],true))throw $this->createNotFoundException('Facture client introuvable ou non émise.');
        $level=filter_var($request->request->get('level'),FILTER_VALIDATE_INT);
        $channel=(string)$request->request->get('channel','email');
        $note=trim((string)$request->request->get('note',''));
        if(!in_array($level,[1,2,3],true)||!in_array($channel,['email','phone','letter'],true)||mb_strlen($note)>2000){
            $this->addFlash('error','Niveau, canal ou commentaire de relance invalide.');
            return $this->redirectToRoute('app_payment_reminders');
        }
        if($channel==='email'&&!$invoice['email']){
            $this->addFlash('error','Aucune adresse e-mail n’est renseignée pour ce client.');
            return $this->redirectToRoute('app_payment_reminders');
        }
        $db->insert('app_customer_payment_reminder',[
            'invoice_id'=>$id,'reminder_level'=>$level,'channel'=>$channel,
            'recipient'=>$channel==='email'?$invoice['email']:null,
            'note'=>$note!==''?$note:null,'sent_at'=>(new DateTimeImmutable())->format('Y-m-d H:i:s')
        ]);
        $this->addFlash('success','La relance envoyée a été enregistrée dans l’historique.');
        return $this->redirectToRoute('app_payment_reminders');
    }
}
