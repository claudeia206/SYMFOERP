<?php
declare(strict_types=1);
namespace App\Controller;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
final class PaymentReminderController extends AbstractController
{
    #[Route('/sales/reminders',name:'app_sales_payment_reminders',methods:['GET'])]
    public function index(Connection $db):Response
    {
        $today=new DateTimeImmutable('today');
        $limit=$today->modify('+7 days');
        $rows=$db->fetchAllAssociative(<<<'SQL'
SELECT i.id,i.number,i.invoice_date,i.due_date,t.name AS customer_name,t.email,
       GREATEST(0,COALESCE(amounts.total,0)-COALESCE(credits.total,0)-COALESCE(payments.paid,0)) AS remaining
FROM app_sales_invoice i
INNER JOIN app_third_party t ON t.id=i.third_party_id
LEFT JOIN (
 SELECT invoice_id,SUM(ROUND(quantity*unit_price,2)+ROUND(ROUND(quantity*unit_price,2)*vat_rate/100,2)) AS total
 FROM app_sales_invoice_line GROUP BY invoice_id
) amounts ON amounts.invoice_id=i.id
LEFT JOIN (
 SELECT n.invoice_id,SUM(ROUND(l.quantity*l.unit_price,2)+ROUND(ROUND(l.quantity*l.unit_price,2)*l.vat_rate/100,2)) AS total
 FROM app_sales_credit_note n INNER JOIN app_sales_credit_note_line l ON l.credit_note_id=n.id GROUP BY n.invoice_id
) credits ON credits.invoice_id=i.id
LEFT JOIN (
 SELECT invoice_id,SUM(amount) AS paid FROM app_sales_payment GROUP BY invoice_id
) payments ON payments.invoice_id=i.id
WHERE i.status IN ('issued','partially_paid')
  AND i.due_date<=?
ORDER BY i.due_date ASC,i.id ASC
SQL,[$limit->format('Y-m-d')]);
        $reminders=[];$overdueTotal=0.0;$upcomingTotal=0.0;
        foreach($rows as $row){
            $remaining=round((float)$row['remaining'],2);
            if($remaining<=0.001)continue;
            $due=new DateTimeImmutable($row['due_date']);
            $daysLate=$due<$today?(int)$due->diff($today)->days:0;
            $stage=$daysLate===0?'Échéance proche':($daysLate<=7?'Première relance':($daysLate<=30?'Deuxième relance':'Relance formelle'));
            $subject=$daysLate===0?'Échéance de votre facture '.$row['number']:'Relance – facture '.$row['number'].' échue';
            $greeting='Bonjour '.$row['customer_name'].",\n\n";
            $body=$daysLate===0
                ?'Nous vous rappelons que la facture '.$row['number'].' d’un montant restant dû de '.number_format($remaining,2,',',' ')." € arrive à échéance le ".$due->format('d/m/Y').".\n\n"
                :'Sauf erreur de notre part, la facture '.$row['number'].' présente un solde restant dû de '.number_format($remaining,2,',',' ')." € et était payable le ".$due->format('d/m/Y').".\n\n";
            $body.='Nous vous remercions de bien vouloir procéder à son règlement ou de nous contacter en cas de difficulté.'."\n\nCordialement";
            $row['remaining']=$remaining;$row['days_late']=$daysLate;$row['stage']=$stage;$row['subject']=$subject;
            $row['mailto']=$row['email']? 'mailto:'.rawurlencode($row['email']).'?subject='.rawurlencode($subject).'&body='.rawurlencode($greeting.$body):null;
            $reminders[]=$row;
            if($daysLate>0)$overdueTotal+=$remaining;else $upcomingTotal+=$remaining;
        }
        return $this->render('payment_reminder/index.html.twig',[
            'reminders'=>$reminders,'overdue_total'=>round($overdueTotal,2),
            'upcoming_total'=>round($upcomingTotal,2),'today'=>$today,
        ]);
    }
}
