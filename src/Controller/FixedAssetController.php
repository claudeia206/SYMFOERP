<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\DepreciationCalculator;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

#[Route('/accounting/fixed-assets')]
final class FixedAssetController extends AbstractController
{
    #[Route('', name: 'app_fixed_assets', methods: ['GET'])]
    public function index(Connection $db, Request $request, DepreciationCalculator $calculator): Response
    {
        $asOfInput = (string) $request->query->get('as_of', 'today');
        try { $asOf = new DateTimeImmutable($asOfInput); } catch (Throwable) { throw $this->createNotFoundException('Date de calcul invalide.'); }
        $assets = $db->fetchAllAssociative('SELECT * FROM app_fixed_asset ORDER BY code');
        $plans = [];
        foreach ($assets as $asset) {
            $plans[(int) $asset['id']] = $calculator->straightLine(
                (float) $asset['acquisition_cost'], (float) $asset['residual_value'],
                (int) $asset['useful_life_years'], new DateTimeImmutable($asset['in_service_date']), $asOf
            );
        }
        $accounts = $db->fetchAllAssociative('SELECT id,code,name FROM app_accounting_account WHERE is_active=1 ORDER BY code');
        $journals = $db->fetchAllAssociative('SELECT id,code,name FROM app_accounting_journal ORDER BY code');
        $posted = $db->fetchAllAssociative('SELECT asset_id,fiscal_year,amount FROM app_fixed_asset_depreciation ORDER BY fiscal_year');
        $postedMap = [];
        foreach ($posted as $item) $postedMap[(int)$item['asset_id']][(string)$item['fiscal_year']] = (float)$item['amount'];
        return $this->render('accounting/fixed_assets.html.twig', [
            'assets'=>$assets, 'plans'=>$plans, 'as_of'=>$asOf->format('Y-m-d'),
            'accounts'=>$accounts, 'journals'=>$journals, 'posted_map'=>$postedMap,
        ]);
    }

    #[Route('/new', name: 'app_fixed_assets_create', methods: ['POST'])]
    public function create(Connection $db, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('fixed_asset_create', (string) $request->request->get('_token'))) throw $this->createAccessDeniedException();
        $code=trim((string)$request->request->get('code')); $name=trim((string)$request->request->get('name'));
        $cost=(float)$request->request->get('acquisition_cost'); $residual=(float)$request->request->get('residual_value');
        $years=(int)$request->request->get('useful_life_years'); $date=(string)$request->request->get('in_service_date');
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        $assetAccount=(int)$request->request->get('asset_account_id'); $accumAccount=(int)$request->request->get('accumulated_account_id');
        $expenseAccount=(int)$request->request->get('expense_account_id'); $journal=(int)$request->request->get('journal_id');
        if ($code==='' || mb_strlen($code)>64 || $name==='' || mb_strlen($name)>255 || !$parsed || $parsed->format('Y-m-d')!==$date || $cost<=0 || $residual<0 || $residual>=$cost || $years<1 || $years>100 || min($assetAccount,$accumAccount,$expenseAccount,$journal)<=0 || count(array_unique([$assetAccount,$accumAccount,$expenseAccount]))!==3) {
            $this->addFlash('error','Vérifiez les données, les comptes doivent être distincts et le journal renseigné.');
            return $this->redirectToRoute('app_fixed_assets');
        }
        $valid=(int)$db->fetchOne('SELECT COUNT(*) FROM app_accounting_account WHERE is_active=1 AND id IN (?,?,?)',[$assetAccount,$accumAccount,$expenseAccount]);
        $validJournal=(int)$db->fetchOne('SELECT COUNT(*) FROM app_accounting_journal WHERE id=?',[$journal]);
        if($valid!==3 || $validJournal!==1){$this->addFlash('error','Compte comptable ou journal invalide.');return $this->redirectToRoute('app_fixed_assets');}
        try {
            $db->insert('app_fixed_asset',[
                'code'=>$code,'name'=>$name,'acquisition_cost'=>number_format($cost,2,'.',''),'residual_value'=>number_format($residual,2,'.',''),
                'useful_life_years'=>$years,'acquisition_date'=>$date,'in_service_date'=>$date,'status'=>'active',
                'asset_account_id'=>$assetAccount,'accumulated_account_id'=>$accumAccount,'expense_account_id'=>$expenseAccount,
                'journal_id'=>$journal,'created_at'=>(new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
            $this->addFlash('success','Immobilisation enregistrée.');
        } catch (Throwable) {$this->addFlash('error','Code immobilisation déjà utilisé ou erreur d’enregistrement.');}
        return $this->redirectToRoute('app_fixed_assets');
    }

    #[Route('/{id}/depreciation/{year}/post', name: 'app_fixed_assets_depreciation_post', requirements: ['id'=>'\d+','year'=>'\d{4}'], methods: ['POST'])]
    public function postDepreciation(int $id, int $year, Connection $db, Request $request, DepreciationCalculator $calculator): Response
    {
        if (!$this->isCsrfTokenValid('fixed_asset_depreciation_'.$id.'_'.$year,(string)$request->request->get('_token'))) throw $this->createAccessDeniedException();
        if($year<2000||$year>2100)throw $this->createNotFoundException();
        try {
            $db->beginTransaction();
            $asset=$db->fetchAssociative('SELECT * FROM app_fixed_asset WHERE id=? AND status=? FOR UPDATE',[$id,'active']);
            if(!$asset)throw new \InvalidArgumentException('Immobilisation active introuvable.');
            if($db->fetchOne('SELECT id FROM app_fixed_asset_depreciation WHERE asset_id=? AND fiscal_year=?',[$id,$year]))throw new \InvalidArgumentException('Dotation déjà comptabilisée pour cet exercice.');
            foreach(['asset_account_id','accumulated_account_id','expense_account_id','journal_id'] as $field) if(empty($asset[$field])) throw new \InvalidArgumentException('Paramétrage comptable incomplet.');
            $periods=$calculator->straightLine((float)$asset['acquisition_cost'],(float)$asset['residual_value'],(int)$asset['useful_life_years'],new DateTimeImmutable($asset['in_service_date']),new DateTimeImmutable($year.'-12-31'));
            $period=null;
            foreach($periods as $candidate)if((int)$candidate['period']===$year)$period=$candidate;
            if(!$period || $period['amount']<=0)throw new \InvalidArgumentException('Aucune dotation à comptabiliser sur cet exercice.');
            $accounts=$db->fetchAllAssociative('SELECT id FROM app_accounting_account WHERE is_active=1 AND id IN (?,?)',[(int)$asset['expense_account_id'],(int)$asset['accumulated_account_id']]);
            if(count($accounts)!==2)throw new \InvalidArgumentException('Les comptes de dotation et d’amortissement doivent être actifs.');
            $journalCode=(string)$db->fetchOne('SELECT code FROM app_accounting_journal WHERE id=?',[(int)$asset['journal_id']]);
            if($journalCode==='')throw new \InvalidArgumentException('Journal comptable introuvable.');
            $entryNumber=(int)$db->fetchOne('SELECT COALESCE(MAX(entry_number),0)+1 FROM app_journal_entry WHERE journal_id=?',[(int)$asset['journal_id']]);
            $entryDate=$year.'-12-31';
            $description=sprintf('Dotation amortissement %s - %d',$asset['code'],$year);
            $reference=sprintf('AMORT-%s-%d',$asset['code'],$year);
            $db->insert('app_journal_entry',['journal_id'=>$asset['journal_id'],'entry_number'=>$entryNumber,'entry_date'=>$entryDate,'reference'=>$reference,'description'=>$description,'status'=>'posted','created_at'=>(new DateTimeImmutable())->format('Y-m-d H:i:s')]);
            $entryId=(int)$db->lastInsertId();$amount=number_format((float)$period['amount'],2,'.','');
            $db->insert('app_journal_entry_line',['entry_id'=>$entryId,'account_id'=>$asset['expense_account_id'],'label'=>$description,'debit'=>$amount,'credit'=>'0.00']);
            $db->insert('app_journal_entry_line',['entry_id'=>$entryId,'account_id'=>$asset['accumulated_account_id'],'label'=>$description,'debit'=>'0.00','credit'=>$amount]);
            $db->insert('app_fixed_asset_depreciation',['asset_id'=>$id,'fiscal_year'=>$year,'amount'=>$amount,'journal_entry_id'=>$entryId,'created_at'=>(new DateTimeImmutable())->format('Y-m-d H:i:s')]);
            $db->commit();
            $this->addFlash('success',sprintf('Dotation de %s € comptabilisée (%s-%06d).',$amount,$journalCode,$entryNumber));
        } catch (\InvalidArgumentException $e) {
            if($db->isTransactionActive())$db->rollBack();$this->addFlash('error',$e->getMessage());
        } catch (Throwable) {
            if($db->isTransactionActive())$db->rollBack();$this->addFlash('error','Échec de la comptabilisation. Aucune dotation n’a été enregistrée.');
        }
        return $this->redirectToRoute('app_fixed_assets',['as_of'=>$year.'-12-31']);
    }

    #[Route('/{id}/archive', name: 'app_fixed_assets_archive', methods: ['POST'])]
    public function archive(int $id, Connection $db, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('fixed_asset_archive_'.$id,(string)$request->request->get('_token'))) throw $this->createAccessDeniedException();
        $db->update('app_fixed_asset',['status'=>'archived'],['id'=>$id]);
        return $this->redirectToRoute('app_fixed_assets');
    }
}