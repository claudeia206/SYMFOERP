<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('dashboard/index.html.twig', [
            'modules' => [
                [
                    'name' => 'Tiers & CRM',
                    'description' => 'Clients, prospects et fournisseurs',
                    'status' => 'Disponible',
                    'route' => 'app_third_parties',
                ],
                ['name' => 'Ventes', 'description' => 'Création et suivi des devis clients', 'status' => 'Disponible', 'route' => 'app_sales_quotes'],
                ['name' => 'Achats', 'description' => 'Demandes, commandes et fournisseurs', 'status' => 'À construire'],
                ['name' => 'Stocks', 'description' => 'Articles, dépôts et mouvements', 'status' => 'À construire'],
            ],
        ]);
    }
}
