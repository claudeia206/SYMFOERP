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
#[Route('/accounting')]
final class GeneralLedgerController extends AbstractController
{
    #[Route('',name:'app_general_ledger',methods:['GET'])]
    public function index(Connection $db,Request $request):Response
    {
        $from=(string)$request->query->get('from',(new DateTimeImmutable('first day of january this year'))->format('Y-m-d'));
        $to=(string)$request->query->get('to',(new DateTimeImmutable('today'))->format('Y-m-d'));
        $this->validatePeriod($from,$to);
        $accounts=$db->fetchAllAssociative('SELECT a.id,a.code,a.name,a.account_class,COALESCE(SUM(CASE WHEN e.id IS NOT NULL THEN l.debit ELSE 0 END),0) AS debit,COALESCE(SUM(CASE WHEN e.id IS NOT NULL THEN l.credit ELSE 0 END),0) AS credit FROM app_accounting_account a LEFT JOIN app_journal_entry_line l ON l.account_id=a.id LEFT JOIN app_journal_entry e ON e.id=l.entry_id AND e.status=? AND e.entry_date BETWEEN ? AND ? WHERE a.is_active=1 GROUP BY a.id,a.code,a.name,a.account_class ORDER BY a.code',['posted',$from,$to]);
        $debitTotal=0.0;$creditTotal=0.0;
        foreach($accounts as &$account){$account['debit']=(float)$account['debit'];$account['credit']=(float)$account['credit'];$account['balance']=round($account['debit']-$account['credit'],2);$debitTotal+=$account['debit'];$creditTotal+=$account['credit'];}
        unset($account);
        $journals=$db->fetchAllAssociative('SELECT id,code,name FROM app_accounting_journal ORDER BY code');
        $entries=$db->fetchAllAssociative('SELECT e.id,e.entry_number,e.entry_date,e.reference,e.description,j.code AS journal_code,j.name AS journal_name,COALESCE(SUM(l.debit),0) AS debit,COALESCE(SUM(l.credit),0) AS credit FROM app_journal_entry e INNER JOIN app_accounting_journal j ON j.id=e.journal_id INNER JOIN app_journal_entry_line l ON l.entry_id=e.id WHERE e.status=? AND e.entry_date BETWEEN ? AND ? GROUP BY e.id,e.entry_number,e.entry_date,e.reference,e.description,j.code,j.name ORDER BY e.entry_date DESC,e.id DESC LIMIT 100',['posted',$from,$to]);
        return $this->render('general_ledger/index.html.twig',['accounts'=>$accounts,'journals'=>$journals,'entries'=>$entries,'from'=>$from,'to'=>$to,'debit_total'=>round($debitTotal,2),'credit_total'=>round($creditTotal,2)]);
    }

    #[Route('/entries',name:'app_general_ledger_entry_create',methods:['POST'])]
    public function create(Request $request,Connection $db):Response
    {
        if(!$this->isCsrfTokenValid('general_ledger_entry',(string)$request->request->get('_token')))throw $this->createAccessDeniedException();
        try{
            $date=(string)$request->request->get('entry_date','');$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
            if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new \InvalidArgumentException('Date d’écriture invalide.');
            $journalId=$this->parsePositiveId($request->request->get('journal_id'));$description=trim((string)$request->request->get('description',''));
            $reference=trim((string)$request->request->get('reference',''));
            if($description===''||mb_strlen($description)>255||mb_strlen($reference)>120)throw new \InvalidArgumentException('Libellé obligatoire (255 caractères maximum) et référence limitée à 120 caractères.');
            if($journalId===null)throw new \InvalidArgumentException('Journal comptable invalide.');
            $journal=$db->fetchAssociative('SELECT id FROM app_accounting_journal WHERE id=?',[$journalId]);
            if(!$journal)throw new \InvalidArgumentException('Journal comptable inconnu.');
            $raw=$request->request->all('lines');
            if(count($raw)<2||count($raw)>30)throw new \InvalidArgumentException('Une écriture doit comporter entre 2 et 30 lignes.');
            $lines=[];$debitCents=0;$creditCents=0;
            foreach($raw as $line){
                if(!is_array($line))throw new \InvalidArgumentException('Format de ligne comptable invalide.');
                $accountId=$this->parsePositiveId($line['account_id']??null);$label=trim((string)($line['label']??''))?:$description;
                $debit=$this->toCents((string)($line['debit']??'0'));$credit=$this->toCents((string)($line['credit']??'0'));
                if($accountId===0&&$debit===0&&$credit===0&&trim((string)($line['label']??''))==='')continue;
                if($accountId===null||$label===''||mb_strlen($label)>255||(($debit>0)===($credit>0)))throw new \InvalidArgumentException('Chaque ligne doit avoir un compte, un libellé et un seul montant au débit ou au crédit.');
                $lines[]=['account_id'=>$accountId,'label'=>$label,'debit'=>$debit,'credit'=>$credit];$debitCents+=$debit;$creditCents+=$credit;
            }
            if($debitCents<=0||$debitCents!==$creditCents)throw new \InvalidArgumentException('Écriture déséquilibrée : le total débit doit être égal au total crédit.');
            $db->beginTransaction();
            try{
                foreach(array_unique(array_column($lines,'account_id')) as $accountId){
                    $account=$db->fetchAssociative('SELECT id FROM app_accounting_account WHERE id=? AND is_active=1 FOR UPDATE',[$accountId]);
                    if(!$account)throw new \InvalidArgumentException('Un compte sélectionné est inexistant ou inactif.');
                }
                $db->fetchOne('SELECT id FROM app_accounting_journal WHERE id=? FOR UPDATE',[$journalId]);
                $number=(int)$db->fetchOne('SELECT COALESCE(MAX(entry_number),0)+1 FROM app_journal_entry WHERE journal_id=?',[$journalId]);
                $db->insert('app_journal_entry',['journal_id'=>$journalId,'entry_number'=>$number,'entry_date'=>$date,'reference'=>$reference!==''?$reference:null,'description'=>$description,'status'=>'posted','created_at'=>(new DateTimeImmutable())->format('Y-m-d H:i:s')]);
                $entryId=(int)$db->lastInsertId();
                foreach($lines as $line)$db->insert('app_journal_entry_line',['entry_id'=>$entryId,'account_id'=>$line['account_id'],'label'=>$line['label'],'debit'=>number_format($line['debit']/100,2,'.',''),'credit'=>number_format($line['credit']/100,2,'.','')]);
                $db->commit();
            }catch(Throwable $e){$db->rollBack();throw $e;}
            $this->addFlash('success',sprintf('Écriture %s-%06d enregistrée et équilibrée.',$db->fetchOne('SELECT code FROM app_accounting_journal WHERE id=?',[$journalId]),$number));
        }catch(\InvalidArgumentException $e){$this->addFlash('error',$e->getMessage());}
        catch(Throwable){$this->addFlash('error','Impossible d’enregistrer cette écriture comptable.');}
        return $this->redirectToRoute('app_general_ledger');
    }

    #[Route('/accounts/{id}',name:'app_general_ledger_account',requirements:['id'=>'\\d+'],methods:['GET'])]
    public function account(int $id,Connection $db,Request $request):Response
    {
        $from=(string)$request->query->get('from',(new DateTimeImmutable('first day of january this year'))->format('Y-m-d'));
        $to=(string)$request->query->get('to',(new DateTimeImmutable('today'))->format('Y-m-d'));$this->validatePeriod($from,$to);
        $account=$db->fetchAssociative('SELECT * FROM app_accounting_account WHERE id=?',[$id]);
        if(!$account)throw $this->createNotFoundException();
        $lines=$db->fetchAllAssociative('SELECT e.entry_date,j.code AS journal_code,e.entry_number,e.reference,e.description,l.label,l.debit,l.credit FROM app_journal_entry_line l INNER JOIN app_journal_entry e ON e.id=l.entry_id INNER JOIN app_accounting_journal j ON j.id=e.journal_id WHERE l.account_id=? AND e.status=? AND e.entry_date BETWEEN ? AND ? ORDER BY e.entry_date,e.id,l.id',[$id,'posted',$from,$to]);
        $balance=0.0;foreach($lines as &$line){$balance=round($balance+(float)$line['debit']-(float)$line['credit'],2);$line['balance']=$balance;}unset($line);
        return $this->render('general_ledger/account.html.twig',['account'=>$account,'lines'=>$lines,'from'=>$from,'to'=>$to,'balance'=>$balance]);
    }

    private function parsePositiveId(mixed $value):?int
    {
        if(!is_string($value)&&!is_int($value))return null;
        $raw=trim((string)$value);
        if(!preg_match('/^[1-9]\d{0,8}$/D',$raw))return null;
        return (int)$raw;
    }

    private function toCents(string $value):int
    {
        $value=str_replace([' ', chr(194).chr(160)], '',trim($value));
        $value=str_replace(',','.',$value);
        if(!preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D',$value))throw new \InvalidArgumentException('Montant invalide : 12 chiffres avant la virgule et deux décimales maximum.');
        $parts=explode('.',$value,2);
        return ((int)$parts[0])*100+(int)str_pad($parts[1]??'0',2,'0');
    }
    private function validatePeriod(string $from,string $to):void
    {
        $a=DateTimeImmutable::createFromFormat('!Y-m-d',$from);$b=DateTimeImmutable::createFromFormat('!Y-m-d',$to);
        if(!$a||!$b||$a->format('Y-m-d')!==$from||$b->format('Y-m-d')!==$to||$from>$to)throw new \InvalidArgumentException('Période comptable invalide.');
    }
}
