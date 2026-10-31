<?php
declare(strict_types=1);
namespace App\Controller;
use App\Service\AccountingAmount;
use App\Service\PaymentReminderInput;
use InvalidArgumentException;
use Throwable;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
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
       GREATEST(0,COALESCE(amounts.total,0)-COALESCE(credits.total,0)-COALESCE(payments.paid,0)) AS remaining,
       COALESCE(reminder_stats.reminder_count,0) AS reminder_count, reminder_stats.last_reminder
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
LEFT JOIN (
 SELECT invoice_id,COUNT(*) AS reminder_count,MAX(sent_at) AS last_reminder
 FROM app_customer_payment_reminder GROUP BY invoice_id
) reminder_stats ON reminder_stats.invoice_id=i.id
WHERE i.status IN ('issued','partially_paid')
  AND i.due_date<=?
ORDER BY i.due_date ASC,i.id ASC
SQL,[$limit->format('Y-m-d')]);
        $reminders=[];$overdueTotal=0;$upcomingTotal=0;
        foreach($rows as $row){
            $remainingCents=AccountingAmount::toCents((string)$row['remaining']);
            if($remainingCents<=0)continue;
            $remaining=AccountingAmount::formatCents($remainingCents);
            $localizedRemaining=AccountingAmount::formatFrenchCents($remainingCents);
            $due=new DateTimeImmutable($row['due_date']);
            $daysLate=$due<$today?(int)$due->diff($today)->days:0;
            $stage=$daysLate===0?'Échéance proche':($daysLate<=7?'Première relance':($daysLate<=30?'Deuxième relance':'Relance formelle'));
            $subject=$daysLate===0?'Échéance de votre facture '.$row['number']:'Relance – facture '.$row['number'].' échue';
            $greeting='Bonjour '.$row['customer_name'].",\n\n";
            $body=$daysLate===0
                ?'Nous vous rappelons que la facture '.$row['number'].' d’un montant restant dû de '.$localizedRemaining." € arrive à échéance le ".$due->format('d/m/Y').".\n\n"
                :'Sauf erreur de notre part, la facture '.$row['number'].' présente un solde restant dû de '.$localizedRemaining." € et était payable le ".$due->format('d/m/Y').".\n\n";
            $body.='Nous vous remercions de bien vouloir procéder à son règlement ou de nous contacter en cas de difficulté.'."\n\nCordialement";
            $row['remaining']=AccountingAmount::formatFrenchCents($remainingCents);$row['days_late']=$daysLate;$row['stage']=$stage;$row['subject']=$subject;
            $row['mailto']=$row['email']? 'mailto:'.rawurlencode($row['email']).'?subject='.rawurlencode($subject).'&body='.rawurlencode($greeting.$body):null;
            $reminders[]=$row;
            if($daysLate>0)$overdueTotal+=$remainingCents;else $upcomingTotal+=$remainingCents;
        }
        $history=$db->fetchAllAssociative(<<<'SQL'
SELECT r.id,r.invoice_id,r.reminder_level,r.channel,r.recipient,r.note,r.sent_at,
       i.number,t.name AS customer_name
FROM app_customer_payment_reminder r
INNER JOIN app_sales_invoice i ON i.id=r.invoice_id
INNER JOIN app_third_party t ON t.id=i.third_party_id
ORDER BY r.sent_at DESC,r.id DESC LIMIT 100
SQL);
        return $this->render('payment_reminder/index.html.twig',[
            'reminders'=>$reminders,'overdue_total'=>AccountingAmount::formatFrenchCents($overdueTotal),
            'upcoming_total'=>AccountingAmount::formatFrenchCents($upcomingTotal),'today'=>$today,'history'=>$history,
        ]);
    }

    #[Route('/sales/invoices/{id}/reminders', name: 'app_sales_payment_reminder_record', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function record(int $id, Request $request, Connection $db): Response
    {
        if (!$this->isCsrfTokenValid('payment_reminder_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        try {
            $db->transactional(function (Connection $connection) use ($id, $request): void {
                $invoice = $connection->fetchAssociative(
                    'SELECT id,status,third_party_id FROM app_sales_invoice WHERE id=? FOR UPDATE',
                    [$id]
                );
                if (!$invoice || !in_array($invoice['status'], ['issued', 'partially_paid'], true)) {
                    throw new InvalidArgumentException('Seule une facture client émise et non soldée peut faire l’objet d’une relance.');
                }

                $email = $connection->fetchOne('SELECT email FROM app_third_party WHERE id=?', [(int) $invoice['third_party_id']]);
                $input = PaymentReminderInput::validate(
                    $request->request->get('level'),
                    $request->request->get('channel', 'email'),
                    $email,
                    $request->request->get('note', '')
                );

                $remaining = $connection->fetchOne(<<<'SQL'
SELECT GREATEST(
    0,
    COALESCE((SELECT SUM(ROUND(quantity*unit_price,2)+ROUND(ROUND(quantity*unit_price,2)*vat_rate/100,2))
              FROM app_sales_invoice_line WHERE invoice_id=?),0)
    - COALESCE((SELECT SUM(ROUND(l.quantity*l.unit_price,2)+ROUND(ROUND(l.quantity*l.unit_price,2)*l.vat_rate/100,2))
                FROM app_sales_credit_note n INNER JOIN app_sales_credit_note_line l ON l.credit_note_id=n.id
                WHERE n.invoice_id=?),0)
    - COALESCE((SELECT SUM(amount) FROM app_sales_payment WHERE invoice_id=?),0)
) AS remaining
SQL, [$id, $id, $id]);
                if (AccountingAmount::toCents((string) $remaining) <= 0) {
                    throw new InvalidArgumentException('Cette facture ne présente plus de solde à recouvrer.');
                }

                $connection->insert('app_customer_payment_reminder', [
                    'invoice_id' => $id,
                    'reminder_level' => $input['level'],
                    'channel' => $input['channel'],
                    'recipient' => $input['recipient'],
                    'note' => $input['note'],
                    'sent_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                ]);
            });
            $this->addFlash('success', 'La relance effectuée a été enregistrée dans l’historique.');
        } catch (InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log('Payment reminder recording failed: '.$exception->getMessage());
            $this->addFlash('error', 'Impossible d’enregistrer la relance. Aucune entrée d’historique n’a été créée.');
        }

        return $this->redirectToRoute('app_sales_payment_reminders');
    }
}
