<?php
declare(strict_types=1);
namespace App\Controller;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;
#[Route('/finance/bank')]
final class BankReconciliationController extends AbstractController
{
    #[Route('',name:'app_bank_reconciliation',methods:['GET'])]
    public function index(Connection $db):Response
    {
        $transactions=$db->fetchAllAssociative('SELECT * FROM app_bank_transaction ORDER BY transaction_date DESC,id DESC LIMIT 250');
        $sales=$db->fetchAllAssociative("SELECT p.id,p.amount,p.paid_at,p.reference,i.number,t.name AS counterparty FROM app_sales_payment p INNER JOIN app_sales_invoice i ON i.id=p.invoice_id INNER JOIN app_third_party t ON t.id=i.third_party_id LEFT JOIN app_bank_transaction b ON b.matched_sales_payment_id=p.id WHERE b.id IS NULL ORDER BY p.paid_at DESC,p.id DESC LIMIT 250");
        $suppliers=$db->fetchAllAssociative("SELECT p.id,p.amount,p.paid_at,p.reference,i.supplier_invoice_number AS number,t.name AS counterparty FROM app_supplier_payment p INNER JOIN app_supplier_invoice i ON i.id=p.invoice_id INNER JOIN app_third_party t ON t.id=i.supplier_id LEFT JOIN app_bank_transaction b ON b.matched_supplier_payment_id=p.id WHERE b.id IS NULL ORDER BY p.paid_at DESC,p.id DESC LIMIT 250");
        return $this->render('bank_reconciliation/index.html.twig',['transactions'=>$transactions,'sales_payments'=>$sales,'supplier_payments'=>$suppliers]);
    }

    #[Route('/import',name:'app_bank_reconciliation_import',methods:['POST'])]
    public function import(Request $request,Connection $db):Response
    {
        if(!$this->isCsrfTokenValid('bank_statement_import',(string)$request->request->get('_token'))) throw $this->createAccessDeniedException();
        $file=$request->files->get('statement');
        if(!$file||!$file->isValid()||$file->getSize()>5*1024*1024){$this->addFlash('error','Fichier CSV absent, invalide ou supérieur à 5 Mo.');return $this->redirectToRoute('app_bank_reconciliation');}
        $handle=fopen($file->getPathname(),'rb');
        if($handle===false){$this->addFlash('error','Lecture du fichier impossible.');return $this->redirectToRoute('app_bank_reconciliation');}
        try {
            $first=fgets($handle); if($first===false) throw new \InvalidArgumentException('Le fichier CSV est vide.');
            $delimiter=substr_count($first,';')>=substr_count($first,',')?';':',';
            rewind($handle); $headers=fgetcsv($handle,0,$delimiter);
            if(!$headers) throw new \InvalidArgumentException('En-tête CSV manquant.');
            $headers=array_map(static fn($v)=>strtolower(trim((string)$v)), $headers);
            if(count($headers)!==count(array_unique($headers))||count($headers)>20) throw new \InvalidArgumentException('En-têtes CSV dupliqués ou trop nombreux.');
            foreach(['date','label','amount'] as $required) if(!in_array($required,$headers,true)) throw new \InvalidArgumentException('Colonnes obligatoires : date, label, amount. Référence facultative : reference.');
            $inserted=0;$duplicates=0;$invalid=0;
            $db->beginTransaction();
            try {
                while(($row=fgetcsv($handle,0,$delimiter))!==false){
                    if(count($row)===1&&trim((string)$row[0])==='') continue;
                    if(count($row)!==count($headers)) { ++$invalid; continue; }
                    $values=[];foreach($headers as $i=>$key)$values[$key]=trim((string)($row[$i]??''));
                    try {
                        $rawDate=$values['date']??'';
                        $date=$this->parseStatementDate($rawDate);
                        $rawAmount=str_replace([' ','€',chr(194).chr(160)],'',$values['amount']??'');
                        if(str_contains($rawAmount,',')&&!str_contains($rawAmount,'.'))$rawAmount=str_replace(',','.',$rawAmount);
                        if(!preg_match('/^-?\d{1,10}(?:\.\d{1,2})?$/D',$rawAmount)) throw new \InvalidArgumentException('Ligne CSV invalide.');
                        $label=trim($values['label']??'');$amount=number_format((float)$rawAmount,2,'.','');
                        if($label===''||abs((float)$amount)<0.005) throw new \InvalidArgumentException('Ligne CSV invalide.');
                        $reference=trim($values['reference']??'');
                        $fingerprint=hash('sha256',$date->format('Y-m-d')."|".$label."|".$amount."|".$reference);
                        $count=$db->executeStatement('INSERT IGNORE INTO app_bank_transaction (transaction_date,label,amount,reference,fingerprint,imported_at) VALUES (?,?,?,?,?,?)',[$date->format('Y-m-d'),mb_substr($label,0,255),$amount,$reference!==''?mb_substr($reference,0,120):null,$fingerprint,(new DateTimeImmutable())->format('Y-m-d H:i:s')]);
                        if($count===1)++$inserted;else ++$duplicates;
                    } catch(\Throwable){++$invalid;}
                }
                $db->commit();
            }catch(\Throwable $e){$db->rollBack();throw $e;}
            $this->addFlash('success',sprintf('Import terminé : %d opération(s), %d doublon(s) ignoré(s), %d ligne(s) invalide(s).',$inserted,$duplicates,$invalid));
        }catch(\InvalidArgumentException $e){$this->addFlash('error',$e->getMessage());}
        catch(Throwable){$this->addFlash('error','L’import bancaire a échoué. Vérifiez le format du fichier.');}
        finally{fclose($handle);}
        return $this->redirectToRoute('app_bank_reconciliation');
    }

    #[Route('/{id}/match',name:'app_bank_reconciliation_match',requirements:['id'=>'\d+'],methods:['POST'])]
    public function match(int $id,Request $request,Connection $db):Response
    {
        if(!$this->isCsrfTokenValid('bank_match_'.$id,(string)$request->request->get('_token'))) throw $this->createAccessDeniedException();
        try {
            $db->beginTransaction();
            $transaction=$db->fetchAssociative('SELECT * FROM app_bank_transaction WHERE id=? FOR UPDATE',[$id]);
            if(!$transaction) throw new \InvalidArgumentException('Opération bancaire introuvable.');
            if($transaction['matched_sales_payment_id']!==null||$transaction['matched_supplier_payment_id']!==null) throw new \InvalidArgumentException('Cette opération est déjà rapprochée.');
            $type=(string)$request->request->get('payment_type');$rawPaymentId=$request->request->get('payment_id');$paymentId=(is_string($rawPaymentId)&&preg_match('/^[1-9]\\d{0,8}$/D',$rawPaymentId))?(int)$rawPaymentId:0;
            if($paymentId<=0||!in_array($type,['sales','supplier'],true))throw new \InvalidArgumentException('Règlement sélectionné invalide.');
            $table=$type==='sales'?'app_sales_payment':'app_supplier_payment';
            $column=$type==='sales'?'matched_sales_payment_id':'matched_supplier_payment_id';
            $payment=$db->fetchAssociative("SELECT id,amount FROM {$table} WHERE id=? FOR UPDATE",[$paymentId]);
            if(!$payment)throw new \InvalidArgumentException('Règlement introuvable.');
            if((int)$db->fetchOne("SELECT COUNT(*) FROM app_bank_transaction WHERE {$column}=?",[$paymentId])!==0)throw new \InvalidArgumentException('Ce règlement est déjà rapproché.');
            $amountCents=$this->toCents((string)$transaction['amount']);
            $expectedCents=$this->toCents((string)$payment['amount']);
            if(($type==='sales'&&$amountCents<=0)||($type==='supplier'&&$amountCents>=0)||abs($amountCents)!==$expectedCents)throw new \InvalidArgumentException('Le sens ou le montant de l’opération ne correspond pas au règlement sélectionné.');
            $updated=$db->executeStatement("UPDATE app_bank_transaction SET {$column}=? WHERE id=? AND matched_sales_payment_id IS NULL AND matched_supplier_payment_id IS NULL",[$paymentId,$id]);
            if($updated!==1)throw new \InvalidArgumentException('Cette opération vient d’être rapprochée par un autre utilisateur.');
            $db->commit();$this->addFlash('success','Opération rapprochée avec le règlement sélectionné.');
        }catch(\InvalidArgumentException $e){if($db->isTransactionActive())$db->rollBack();$this->addFlash('error',$e->getMessage());}
        catch(Throwable){if($db->isTransactionActive())$db->rollBack();$this->addFlash('error','Le rapprochement a échoué.');}
        return $this->redirectToRoute('app_bank_reconciliation');
    }

    private function parseStatementDate(string $value): DateTimeImmutable
    {
        foreach (['!Y-m-d', '!d/m/Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date;
            }
        }
        throw new \InvalidArgumentException('Date bancaire invalide.');
    }

    private function toCents(string $value): int
    {
        if (!preg_match('/^-?(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/D', $value)) {
            throw new \InvalidArgumentException('Montant bancaire invalide.');
        }
        $negative = str_starts_with($value, '-');
        $unsigned = $negative ? substr($value, 1) : $value;
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $cents = ((int) $whole) * 100 + (int) str_pad($fraction, 2, '0');
        return $negative ? -$cents : $cents;
    }

}
