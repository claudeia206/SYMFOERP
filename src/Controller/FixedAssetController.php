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

#[Route('/accounting/fixed-assets')]
final class FixedAssetController extends AbstractController
{
    #[Route('', name: 'app_fixed_assets', methods: ['GET'])]
    public function index(Connection $db, Request $request, DepreciationCalculator $calculator): Response
    {
        $assets = $db->fetchAllAssociative('SELECT * FROM app_fixed_asset ORDER BY code');
        $asOf = new DateTimeImmutable((string) $request->query->get('as_of', 'today'));
        $plans = [];
        foreach ($assets as $asset) {
            $plans[(int) $asset['id']] = $calculator->straightLine(
                (float) $asset['acquisition_cost'], (float) $asset['residual_value'],
                (int) $asset['useful_life_years'], new DateTimeImmutable($asset['in_service_date']), $asOf
            );
        }
        return $this->render('accounting/fixed_assets.html.twig', ['assets'=>$assets, 'plans'=>$plans, 'as_of'=>$asOf->format('Y-m-d')]);
    }

    #[Route('/new', name: 'app_fixed_assets_create', methods: ['POST'])]
    public function create(Connection $db, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('fixed_asset_create', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $code = trim((string) $request->request->get('code'));
        $name = trim((string) $request->request->get('name'));
        $cost = (float) $request->request->get('acquisition_cost');
        $residual = (float) $request->request->get('residual_value');
        $years = (int) $request->request->get('useful_life_years');
        $date = (string) $request->request->get('in_service_date');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($code === '' || $name === '' || !$parsed || $parsed->format('Y-m-d') !== $date || $cost <= 0 || $residual < 0 || $residual >= $cost || $years < 1 || $years > 100) {
            $this->addFlash('error', 'Vérifiez les données de l’immobilisation.');
            return $this->redirectToRoute('app_fixed_assets');
        }
        $db->insert('app_fixed_asset', [
            'code'=>$code, 'name'=>$name, 'acquisition_cost'=>$cost, 'residual_value'=>$residual,
            'useful_life_years'=>$years, 'acquisition_date'=>$date, 'in_service_date'=>$date,
            'status'=>'active', 'created_at'=>(new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        return $this->redirectToRoute('app_fixed_assets');
    }

    #[Route('/{id}/archive', name: 'app_fixed_assets_archive', methods: ['POST'])]
    public function archive(int $id, Connection $db, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('fixed_asset_archive_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $db->update('app_fixed_asset', ['status'=>'archived'], ['id'=>$id]);
        return $this->redirectToRoute('app_fixed_assets');
    }
}