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


    #[Route('/export.csv', name: 'app_fixed_assets_export', methods: ['GET'])]
    public function export(Connection $db): Response
    {
        $assets = $db->fetchAllAssociative(
            'SELECT a.*, COALESCE(d.total_depreciation, 0) AS total_depreciation,
                    d.last_depreciation_year
             FROM app_fixed_asset a
             LEFT JOIN (
                 SELECT asset_id, SUM(amount) AS total_depreciation,
                        MAX(fiscal_year) AS last_depreciation_year
                 FROM app_fixed_asset_depreciation
                 GROUP BY asset_id
             ) d ON d.asset_id = a.id
             ORDER BY a.code'
        );

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Impossible de préparer le fichier CSV.');
        }

        // BOM UTF-8 pour une ouverture correcte dans Excel.
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, [
            'Code', 'Immobilisation', 'Date acquisition', 'Mise en service',
            'Coût brut', 'Amortissements comptabilisés', 'VNC comptable',
            'Durée (ans)', 'Dernier exercice amorti', 'Statut',
            'Date de sortie', 'Prix de cession',
        ], ';', '"', '\\');

        $safeText = static function (mixed $value): string {
            $value = (string) ($value ?? '');
            // Neutralise les formules Excel/LibreOffice dans les champs texte.
            return preg_match('/^[\s\x00-\x1F]*[=+\-@]/u', $value) === 1
                ? "'" . $value
                : $value;
        };

        foreach ($assets as $asset) {
            $cost = (float) $asset['acquisition_cost'];
            $residual = (float) $asset['residual_value'];
            $accumulated = (float) $asset['total_depreciation'];
            $netBookValue = max($residual, round($cost - $accumulated, 2));

            fputcsv($stream, [
                $safeText($asset['code']),
                $safeText($asset['name']),
                $asset['acquisition_date'],
                $asset['in_service_date'],
                number_format($cost, 2, '.', ''),
                number_format($accumulated, 2, '.', ''),
                number_format($netBookValue, 2, '.', ''),
                (string) $asset['useful_life_years'],
                $asset['last_depreciation_year'] ?? '',
                $safeText($asset['status']),
                $asset['disposal_date'] ?? '',
                $asset['disposal_proceeds'] === null
                    ? ''
                    : number_format((float) $asset['disposal_proceeds'], 2, '.', ''),
            ], ';', '"', '\\');
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        $response = new Response($csv === false ? '' : $csv);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="registre-immobilisations.csv"');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    #[Route('/new', name: 'app_fixed_assets_create', methods: ['POST'])]
    public function create(Connection $db, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('fixed_asset_create', (string) $request->request->get('_token'))) throw $this->createAccessDeniedException();
        $code=trim((string)$request->request->get('code')); $name=trim((string)$request->request->get('name'));
        $cost=(float)$request->request->get('acquisition_cost'); $residual=(float)$request->request->get('residual_value');
        $years=(int)$request->request->get('useful_life_years');
        $acquisitionDate=(string)$request->request->get('acquisition_date');
        $serviceDate=(string)$request->request->get('in_service_date');
        $parsedAcquisition=DateTimeImmutable::createFromFormat('!Y-m-d',$acquisitionDate);
        $parsedService=DateTimeImmutable::createFromFormat('!Y-m-d',$serviceDate);
        $assetAccount=(int)$request->request->get('asset_account_id'); $accumAccount=(int)$request->request->get('accumulated_account_id');
        $expenseAccount=(int)$request->request->get('expense_account_id'); $journal=(int)$request->request->get('journal_id');
        $bankAccount=(int)$request->request->get('disposal_bank_account_id'); $gainAccount=(int)$request->request->get('disposal_gain_account_id'); $lossAccount=(int)$request->request->get('disposal_loss_account_id');
        if ($code==='' || mb_strlen($code)>64 || $name==='' || mb_strlen($name)>255 || !$parsedAcquisition || $parsedAcquisition->format('Y-m-d')!==$acquisitionDate || !$parsedService || $parsedService->format('Y-m-d')!==$serviceDate || $parsedAcquisition>$parsedService || $cost<=0 || $residual<0 || $residual>=$cost || $years<1 || $years>100 || min($assetAccount,$accumAccount,$expenseAccount,$journal,$bankAccount,$gainAccount,$lossAccount)<=0 || count(array_unique([$assetAccount,$accumAccount,$expenseAccount,$bankAccount,$gainAccount,$lossAccount]))!==6) {
            $this->addFlash('error','Vérifiez les données, les comptes doivent être distincts et le journal renseigné.');
            return $this->redirectToRoute('app_fixed_assets');
        }
        $valid=(int)$db->fetchOne('SELECT COUNT(*) FROM app_accounting_account WHERE is_active=1 AND id IN (?,?,?,?,?,?)',[$assetAccount,$accumAccount,$expenseAccount,$bankAccount,$gainAccount,$lossAccount]);
        $validJournal=(int)$db->fetchOne('SELECT COUNT(*) FROM app_accounting_journal WHERE id=?',[$journal]);
        if($valid!==6 || $validJournal!==1){$this->addFlash('error','Compte comptable ou journal invalide.');return $this->redirectToRoute('app_fixed_assets');}
        try {
            $db->insert('app_fixed_asset',[
                'code'=>$code,'name'=>$name,'acquisition_cost'=>number_format($cost,2,'.',''),'residual_value'=>number_format($residual,2,'.',''),
                'useful_life_years'=>$years,'acquisition_date'=>$acquisitionDate,'in_service_date'=>$serviceDate,'status'=>'active',
                'asset_account_id'=>$assetAccount,'accumulated_account_id'=>$accumAccount,'expense_account_id'=>$expenseAccount,
                'journal_id'=>$journal,'disposal_bank_account_id'=>$bankAccount,'disposal_gain_account_id'=>$gainAccount,
                'disposal_loss_account_id'=>$lossAccount,'created_at'=>(new DateTimeImmutable())->format('Y-m-d H:i:s'),
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
            $db->fetchOne('SELECT id FROM app_accounting_journal WHERE id=? FOR UPDATE',[(int)$asset['journal_id']]);
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

    #[Route('/{id}/dispose', name: 'app_fixed_assets_dispose', requirements: ['id'=>'\d+'], methods: ['POST'])]
    public function dispose(int $id, Connection $db, Request $request, DepreciationCalculator $calculator): Response
    {
        if(!$this->isCsrfTokenValid('fixed_asset_dispose_'.$id,(string)$request->request->get('_token')))throw $this->createAccessDeniedException();
        $date=(string)$request->request->get('disposal_date','');$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        $proceeds=(float)$request->request->get('disposal_proceeds',-1);
        if(!$parsed||$parsed->format('Y-m-d')!==$date||$parsed->format('m-d')!=='12-31'||$proceeds<0){
            $this->addFlash('error','La sortie doit être datée du 31 décembre et le prix de cession doit être positif ou nul.');
            return $this->redirectToRoute('app_fixed_assets');
        }
        try{
            $db->beginTransaction();
            $asset=$db->fetchAssociative('SELECT * FROM app_fixed_asset WHERE id=? AND status=? FOR UPDATE',[$id,'active']);
            if(!$asset)throw new \InvalidArgumentException('Immobilisation active introuvable.');
            foreach(['asset_account_id','accumulated_account_id','disposal_bank_account_id','disposal_gain_account_id','disposal_loss_account_id','journal_id'] as $field)
                if(empty($asset[$field]))throw new \InvalidArgumentException('Paramétrage de cession incomplet.');
            $asOf=new DateTimeImmutable($date);
            $plan=$calculator->straightLine((float)$asset['acquisition_cost'],(float)$asset['residual_value'],(int)$asset['useful_life_years'],new DateTimeImmutable($asset['in_service_date']),$asOf);
            $posted=$db->fetchAllAssociative('SELECT fiscal_year,amount FROM app_fixed_asset_depreciation WHERE asset_id=? ORDER BY fiscal_year FOR UPDATE',[$id]);
            $postedByYear=[];foreach($posted as $row)$postedByYear[(int)$row['fiscal_year']]=(float)$row['amount'];
            $accumulated=0.0;
            foreach($plan as $period){
                $periodYear=(int)$period['period'];
                if(!isset($postedByYear[$periodYear]))throw new \InvalidArgumentException(sprintf('Comptabilisez d’abord la dotation %d avant la sortie.',$periodYear));
                if(abs($postedByYear[$periodYear]-(float)$period['amount'])>0.01)throw new \InvalidArgumentException(sprintf('La dotation %d ne correspond plus au plan calculé.',$periodYear));
                $accumulated+=$postedByYear[$periodYear];
            }
            $cost=(float)$asset['acquisition_cost'];$bookValue=max(0.0,round($cost-$accumulated,2));
            $gain=round($proceeds-$bookValue,2);$amounts=[
                (int)$asset['disposal_bank_account_id'],(int)$asset['accumulated_account_id'],
                (int)$asset['asset_account_id'],(int)($gain>=0?$asset['disposal_gain_account_id']:$asset['disposal_loss_account_id'])
            ];
            if(count(array_unique($amounts))!==4)throw new \InvalidArgumentException('Les quatre comptes de cession doivent être distincts.');
            $valid=(int)$db->fetchOne('SELECT COUNT(*) FROM app_accounting_account WHERE is_active=1 AND id IN (?,?,?,?)',$amounts);
            if($valid!==4)throw new \InvalidArgumentException('Un compte de cession est inexistant ou inactif.');
            $journalId=(int)$asset['journal_id'];$journalCode=(string)$db->fetchOne('SELECT code FROM app_accounting_journal WHERE id=?',[$journalId]);
            if($journalCode==='')throw new \InvalidArgumentException('Journal comptable introuvable.');
            $db->fetchOne('SELECT id FROM app_accounting_journal WHERE id=? FOR UPDATE',[$journalId]);
            $number=(int)$db->fetchOne('SELECT COALESCE(MAX(entry_number),0)+1 FROM app_journal_entry WHERE journal_id=?',[$journalId]);
            $description=sprintf('Sortie immobilisation %s - cession %s',$asset['code'],$date);
            $db->insert('app_journal_entry',['journal_id'=>$journalId,'entry_number'=>$number,'entry_date'=>$date,'reference'=>'CESSION-'.$asset['code'],'description'=>$description,'status'=>'posted','created_at'=>(new DateTimeImmutable())->format('Y-m-d H:i:s')]);
            $entryId=(int)$db->lastInsertId();$fmt=static fn(float $v):string=>number_format($v,2,'.','');
            $lines=[
                ['account_id'=>$asset['disposal_bank_account_id'],'label'=>'Prix de cession '.$asset['code'],'debit'=>$fmt($proceeds),'credit'=>'0.00'],
                ['account_id'=>$asset['accumulated_account_id'],'label'=>'Reprise amortissements '.$asset['code'],'debit'=>$fmt($accumulated),'credit'=>'0.00'],
                ['account_id'=>$asset['asset_account_id'],'label'=>'Sortie valeur brute '.$asset['code'],'debit'=>'0.00','credit'=>$fmt($cost)],
            ];
            if($gain>=0)$lines[]=['account_id'=>$asset['disposal_gain_account_id'],'label'=>'Plus-value de cession '.$asset['code'],'debit'=>'0.00','credit'=>$fmt($gain)];
            else $lines[]=['account_id'=>$asset['disposal_loss_account_id'],'label'=>'Moins-value de cession '.$asset['code'],'debit'=>$fmt(abs($gain)),'credit'=>'0.00'];
            foreach($lines as $line)$db->insert('app_journal_entry_line',['entry_id'=>$entryId]+$line);
            $db->update('app_fixed_asset',['status'=>'disposed','disposal_date'=>$date,'disposal_proceeds'=>$fmt($proceeds),'disposal_journal_entry_id'=>$entryId],['id'=>$id]);
            $db->commit();
            $this->addFlash('success',sprintf('Sortie comptabilisée. VNC : %s €, résultat de cession : %s €.',number_format($bookValue,2,',',' '),number_format($gain,2,',',' ')));
        }catch(\InvalidArgumentException $e){if($db->isTransactionActive())$db->rollBack();$this->addFlash('error',$e->getMessage());}
        catch(Throwable){if($db->isTransactionActive())$db->rollBack();$this->addFlash('error','Échec de la sortie. Aucune écriture n’a été enregistrée.');}
        return $this->redirectToRoute('app_fixed_assets',['as_of'=>$date]);
    }

    #[Route('/{id}/archive', name: 'app_fixed_assets_archive', requirements: ['id'=>'\\d+'], methods: ['POST'])]
    public function archive(int $id, Connection $db, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('fixed_asset_archive_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        try {
            $db->beginTransaction();
            $asset = $db->fetchAssociative(
                'SELECT id, status, disposal_journal_entry_id FROM app_fixed_asset WHERE id = ? FOR UPDATE',
                [$id]
            );
            if (!$asset || $asset['status'] !== 'disposed' || empty($asset['disposal_journal_entry_id'])) {
                throw new \\InvalidArgumentException('Seules les immobilisations sorties et comptabilisées peuvent être archivées.');
            }

            $db->update('app_fixed_asset', ['status' => 'archived'], ['id' => $id]);
            $db->commit();
            $this->addFlash('success', 'Immobilisation archivée.');
        } catch (\\InvalidArgumentException $e) {
            if ($db->isTransactionActive()) $db->rollBack();
            $this->addFlash('error', $e->getMessage());
        } catch (Throwable) {
            if ($db->isTransactionActive()) $db->rollBack();
            $this->addFlash('error', 'Impossible d’archiver cette immobilisation.');
        }

        return $this->redirectToRoute('app_fixed_assets');
    }
}