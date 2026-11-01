<?php

declare(strict_types=1);

namespace App\\Controller;

use Doctrine\\DBAL\\Connection;
use Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController;
use Symfony\\Component\\HttpFoundation\\Response;
use Symfony\\Component\\Routing\\Attribute\\Route;

final class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function index(Connection $connection): Response
    {
        $schema = $connection->createSchemaManager();
        $count = static function (string $table) use ($schema, $connection): int {
            if (!$schema->tablesExist([$table])) {
                return 0;
            }

            return (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $connection->quoteIdentifier($table)));
        };

        $metrics = [
            ['label' => 'Tiers enregistrés', 'value' => $count('app_third_party'), 'hint' => 'Clients, prospects et fournisseurs', 'route' => 'app_third_parties'],
            ['label' => 'Produits actifs', 'value' => $count('app_product'), 'hint' => 'Références du catalogue', 'route' => 'app_products'],
            ['label' => 'Devis', 'value' => $count('app_sales_quote'), 'hint' => 'Tous statuts confondus', 'route' => 'app_sales_quotes'],
            ['label' => 'Factures clients', 'value' => $count('app_sales_invoice'), 'hint' => 'Suivi de la facturation', 'route' => 'app_sales_invoices'],
        ];

        $alerts = [];
        if ($schema->tablesExist(['app_product'])) {
            $lowStock = (int) $connection->fetchOne('SELECT COUNT(*) FROM app_product WHERE active = 1 AND stock_tracked = 1 AND stock_quantity <= stock_alert_threshold');
            if ($lowStock > 0) {
                $alerts[] = ['label' => 'Références en alerte de stock', 'value' => $lowStock, 'route' => 'app_products'];
            }
        }
        if ($schema->tablesExist(['app_sales_invoice'])) {
            $unpaid = (int) $connection->fetchOne("SELECT COUNT(*) FROM app_sales_invoice WHERE status IN ('issued', 'partially_paid', 'overdue')");
            if ($unpaid > 0) {
                $alerts[] = ['label' => 'Factures clients à suivre', 'value' => $unpaid, 'route' => 'app_sales_invoices'];
            }
        }
        if ($schema->tablesExist(['app_supplier_invoice'])) {
            $supplierDue = (int) $connection->fetchOne("SELECT COUNT(*) FROM app_supplier_invoice WHERE status IN ('received', 'partially_paid', 'overdue')");
            if ($supplierDue > 0) {
                $alerts[] = ['label' => 'Factures fournisseurs à régler', 'value' => $supplierDue, 'route' => 'app_supplier_invoices'];
            }
        }

        return $this->render('dashboard/index.html.twig', [
            'metrics' => $metrics,
            'alerts' => $alerts,
            'modules' => [
                ['name' => 'Tiers & CRM', 'description' => 'Clients, prospects et fournisseurs', 'status' => 'Disponible', 'route' => 'app_third_parties'],
                ['name' => 'Catalogue produits', 'description' => 'Références, tarifs et niveaux de stock', 'status' => 'Disponible', 'route' => 'app_products'],
                ['name' => 'Devis clients', 'description' => 'Création, suivi et conversion des devis', 'status' => 'Disponible', 'route' => 'app_sales_quotes'],
                ['name' => 'Commandes clients', 'description' => 'Suivi des commandes et sorties de stock', 'status' => 'Disponible', 'route' => 'app_sales_orders'],
                ['name' => 'Factures clients', 'description' => 'Émission, PDF et suivi des règlements', 'status' => 'Disponible', 'route' => 'app_sales_invoices'],
                ['name' => 'Commandes fournisseurs', 'description' => 'Approvisionnement et réceptions partielles', 'status' => 'Disponible', 'route' => 'app_purchase_orders'],
                ['name' => 'Factures fournisseurs', 'description' => 'Validation des factures et règlements sortants', 'status' => 'Disponible', 'route' => 'app_supplier_invoices'],
            ],
        ]);
    }
}
