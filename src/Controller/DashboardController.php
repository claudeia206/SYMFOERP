<?php

namespace App\Controller;

use App\Repository\ProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function index(ProductRepository $products): Response
    {
        return $this->render('dashboard/index.html.twig', [
            'low_stock_count' => $products->countLowStock(),
            'modules' => [
                ['name' => 'Tiers & CRM', 'description' => 'Clients, prospects et fournisseurs', 'status' => 'Disponible', 'route' => 'app_third_parties'],
                ['name' => 'Catalogue produits', 'description' => 'Références, tarifs et niveaux de stock', 'status' => 'Disponible', 'route' => 'app_products'],
                ['name' => 'Devis clients', 'description' => 'Création, suivi et conversion des devis', 'status' => 'Disponible', 'route' => 'app_sales_quotes'],
                ['name' => 'Commandes clients', 'description' => 'Suivi des commandes et sorties de stock', 'status' => 'Disponible', 'route' => 'app_sales_orders'],
                ['name' => 'Factures clients', 'description' => 'Émission, PDF et suivi des règlements', 'status' => 'Disponible', 'route' => 'app_sales_invoices'],
                ['name' => 'Relances clients', 'description' => 'Suivi des échéances et préparation des e-mails de relance', 'status' => 'Disponible', 'route' => 'app_sales_payment_reminders'],
                ['name' => 'Commandes fournisseurs', 'description' => 'Approvisionnement et réceptions partielles', 'status' => 'Disponible', 'route' => 'app_purchase_orders'],
                ['name' => 'Factures fournisseurs', 'description' => 'Validation des factures et règlements sortants', 'status' => 'Disponible', 'route' => 'app_supplier_invoices'],
                ['name' => 'Reporting financier', 'description' => 'Créances, dettes et flux de trésorerie', 'status' => 'Disponible', 'route' => 'app_financial_report'],
                ['name' => 'Comptabilité générale', 'description' => 'Plan comptable, journaux, balance et grand livre', 'status' => 'Disponible', 'route' => 'app_general_ledger'],
                ['name' => 'Déclaration de TVA', 'description' => 'TVA collectée, déductible et export CSV', 'status' => 'Disponible', 'route' => 'app_vat_declaration'],
                ['name' => 'Rapprochement bancaire', 'description' => 'Import CSV et rapprochement des règlements', 'status' => 'Disponible', 'route' => 'app_bank_reconciliation'],
                ['name' => 'Prévision de trésorerie', 'description' => 'Projection des flux sur 13 semaines', 'status' => 'Disponible', 'route' => 'app_cash_flow_forecast'],
            ],
        ]);
    }
}
