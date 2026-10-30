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
final class FixedAssetExportController extends AbstractController
{
    #[Route('/export/csv', name: 'app_fixed_assets_export', methods: ['GET'])]
    public function export(Connection $db, Request $request, DepreciationCalculator $calculator): Response
    {
        try { $asOf = new DateTimeImmutable((string)$request->query->get('as_of', 'today')); }
        catch (Throwable) { throw $this->createNotFoundException('Date de calcul invalide.'); }
        $assets=$db->fetchAllAssociative('SELECT * FROM app_fixed_asset ORDER BY code');
        $posted=$db->fetchAllAssociative('SELECT asset_id,fiscal_year,amount FROM app_fixed_asset_depreciation');
        $postedMap=[];
        foreach($posted as $row)$postedMap[(int)$row['asset_id']][(int)$row['fiscal_year']]=(float)$row['amount'];
        $stream=fopen('php://temp','r+');
        fwrite($stream,"\xEF\xBB\xBF");
        fputcsv($stream,['Code','Immobilisation','Statut','Date mise en service','Coût acquisition','Valeur résiduelle','Durée années','Exercice','Dotation calculée','Dotation comptabilisée','Amortissements cumulés','Valeur nette comptable'],';');
        foreach($assets as $asset){
            $plan=$calculator->straightLine((float)$asset['acquisition_cost'],(float)$asset['residual_value'],(int)$asset['useful_life_years'],new DateTimeImmutable($asset['in_service_date']),$asOf);
            foreach($plan as $line){
                $year=(int)$line['period'];
                fputcsv($stream,[$asset['code'],$asset['name'],$asset['status'],$asset['in_service_date'],$asset['acquisition_cost'],$asset['residual_value'],$asset['useful_life_years'],$year,number_format($line['amount'],2,'.',''),isset($postedMap[(int)$asset['id']][$year])?number_format($postedMap[(int)$asset['id']][$year],2,'.',''):'',number_format($line['accumulated'],2,'.',''),number_format($line['net_book_value'],2,'.','')],';');
            }
        }
        rewind($stream);$csv=stream_get_contents($stream);fclose($stream);
        return new Response($csv,200,['Content-Type'=>'text/csv; charset=UTF-8','Content-Disposition'=>'attachment; filename="registre-amortissements-'.$asOf->format('Y-m-d').'.csv"']);
    }
}