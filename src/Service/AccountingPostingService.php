<?php
declare(strict_types=1);
namespace App\Service;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use RuntimeException;
final class AccountingPostingService
{
    public function __construct(private readonly Connection $db) {}
    public function postSalesInvoice(int $invoiceId): void
    {
        if ($this->alreadyPosted('sales_invoice',$invoiceId)) return;
        $invoice=$this->db->fetchAssociative('SELECT number,invoice_date FROM app_sales_invoice WHERE id=? AND number IS NOT NULL',[$invoiceId]);
        if(!$invoice) throw new RuntimeException('Facture client émise introuvable pour comptabilisation.');
        $groups=$this->db->fetchAllAssociative('SELECT vat_rate,SUM(ROUND(quantity*unit_price,2)) AS net,SUM(ROUND(ROUND(quantity*unit_price,2)*vat_rate/100,2)) AS tax FROM app_sales_invoice_line WHERE invoice_id=? GROUP BY vat_rate ORDER BY vat_rate',[$invoiceId]);
        $lines=[];$totalNet=0;$totalTax=0;
        foreach($groups as $group){$net=$this->cents($group['net']);$tax=$this->cents($group['tax']);if($net>0)$lines[]=['account'=>'707000','label'=>'Vente HT – TVA '.$group['vat_rate'].'%','debit'=>0,'credit'=>$net];if($tax>0)$lines[]=['account'=>'445710','label'=>'TVA collectée – '.$group['vat_rate'].'%','debit'=>0,'credit'=>$tax];$totalNet+=$net;$totalTax+=$tax;}
        if($totalNet+$totalTax<=0)throw new RuntimeException('La facture ne comporte aucun montant comptabilisable.');
        array_unshift($lines,['account'=>'411000','label'=>'Client '.$invoice['number'],'debit'=>$totalNet+$totalTax,'credit'=>0]);
        $this->post('sales_invoice',$invoiceId,'VE',$invoice['invoice_date'],$invoice['number'],'Facture client '.$invoice['number'],$lines);
    }
    public function postSalesPayment(int $paymentId): void
    {
        if($this->alreadyPosted('sales_payment',$paymentId))return;
        $payment=$this->db->fetchAssociative('SELECT p.amount,p.paid_at,p.method,p.reference,i.number FROM app_sales_payment p INNER JOIN app_sales_invoice i ON i.id=p.invoice_id WHERE p.id=?',[$paymentId]);
        if(!$payment)throw new RuntimeException('Règlement client introuvable pour comptabilisation.');
        $amount=$this->cents($payment['amount']);$account=$payment['method']==='cash'?'530000':'512000';$label='Règlement facture '.$payment['number'];
        $this->post('sales_payment',$paymentId,'BQ',$payment['paid_at'],$payment['reference']?:$payment['number'],$label,[['account'=>$account,'label'=>$label,'debit'=>$amount,'credit'=>0],['account'=>'411000','label'=>'Lettrage client '.$payment['number'],'debit'=>0,'credit'=>$amount]]);
    }
    public function postSalesCreditNote(int $creditNoteId): void
    {
        if($this->alreadyPosted('sales_credit_note',$creditNoteId))return;
        $note=$this->db->fetchAssociative('SELECT n.number,n.credit_date,i.number AS invoice_number FROM app_sales_credit_note n INNER JOIN app_sales_invoice i ON i.id=n.invoice_id WHERE n.id=? AND n.number IS NOT NULL',[$creditNoteId]);
        if(!$note)throw new RuntimeException('Avoir client émis introuvable pour comptabilisation.');
        $groups=$this->db->fetchAllAssociative('SELECT vat_rate,SUM(ROUND(quantity*unit_price,2)) AS net,SUM(ROUND(ROUND(quantity*unit_price,2)*vat_rate/100,2)) AS tax FROM app_sales_credit_note_line WHERE credit_note_id=? GROUP BY vat_rate ORDER BY vat_rate',[$creditNoteId]);
        $lines=[];$totalNet=0;$totalTax=0;
        foreach($groups as $group){$net=$this->cents($group['net']);$tax=$this->cents($group['tax']);if($net>0)$lines[]=['account'=>'707000','label'=>'Annulation vente HT – avoir '.$note['number'],'debit'=>$net,'credit'=>0];if($tax>0)$lines[]=['account'=>'445710','label'=>'Annulation TVA collectée – avoir '.$note['number'],'debit'=>$tax,'credit'=>0];$totalNet+=$net;$totalTax+=$tax;}
        if($totalNet+$totalTax<=0)throw new RuntimeException('L’avoir ne comporte aucun montant comptabilisable.');
        $lines[]=['account'=>'411000','label'=>'Avoir client '.$note['number'],'debit'=>0,'credit'=>$totalNet+$totalTax];
        $this->post('sales_credit_note',$creditNoteId,'VE',$note['credit_date'],$note['number'],'Avoir client '.$note['number'].' sur '.$note['invoice_number'],$lines);
    }
    private function alreadyPosted(string $type,int $id):bool { return (bool)$this->db->fetchOne('SELECT 1 FROM app_accounting_source_posting WHERE source_type=? AND source_id=?',[$type,$id]); }
    private function post(string $type,int $sourceId,string $journalCode,string $date,?string $reference,string $description,array $lines):void
    {
        $this->db->transactional(function(Connection $db)use($type,$sourceId,$journalCode,$date,$reference,$description,$lines):void{
            if($db->fetchOne('SELECT 1 FROM app_accounting_source_posting WHERE source_type=? AND source_id=?',[$type,$sourceId]))return;
            $journal=$db->fetchAssociative('SELECT id,code FROM app_accounting_journal WHERE code=? FOR UPDATE',[$journalCode]);
            if(!$journal)throw new RuntimeException('Journal '.$journalCode.' non configuré.');
            $debit=0;$credit=0;$resolved=[];
            foreach($lines as $line){$account=$db->fetchAssociative('SELECT id FROM app_accounting_account WHERE code=? AND is_active=1',[$line['account']]);if(!$account)throw new RuntimeException('Compte '.$line['account'].' absent ou inactif.');$debit+=(int)$line['debit'];$credit+=(int)$line['credit'];$resolved[]=['account_id'=>(int)$account['id'],'label'=>$line['label'],'debit'=>(int)$line['debit'],'credit'=>(int)$line['credit']];}
            if($debit<=0||$debit!==$credit)throw new RuntimeException('Écriture automatique déséquilibrée.');
            $number=(int)$db->fetchOne('SELECT COALESCE(MAX(entry_number),0)+1 FROM app_journal_entry WHERE journal_id=?',[$journal['id']]);
            $db->insert('app_journal_entry',['journal_id'=>$journal['id'],'entry_number'=>$number,'entry_date'=>$date,'reference'=>$reference,'description'=>mb_substr($description,0,255),'status'=>'posted','created_at'=>(new DateTimeImmutable())->format('Y-m-d H:i:s')]);
            $entryId=(int)$db->lastInsertId();
            foreach($resolved as $line)$db->insert('app_journal_entry_line',['entry_id'=>$entryId,'account_id'=>$line['account_id'],'label'=>mb_substr($line['label'],0,255),'debit'=>number_format($line['debit']/100,2,'.',''),'credit'=>number_format($line['credit']/100,2,'.','')]);
            $db->insert('app_accounting_source_posting',['source_type'=>$type,'source_id'=>$sourceId,'entry_id'=>$entryId,'posted_at'=>(new DateTimeImmutable())->format('Y-m-d H:i:s')]);
        });
    }
    private function cents(mixed $amount):int { return (int)round((float)$amount*100); }
}
