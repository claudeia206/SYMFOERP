<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track invoiced received quantities and allow multiple supplier invoices per purchase order.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_purchase_order_line ADD invoiced_quantity NUMERIC(10, 3) NOT NULL DEFAULT 0.000');
        $this->addSql('ALTER TABLE app_supplier_invoice DROP INDEX uniq_supplier_invoice_order');
        $this->addSql('CREATE INDEX idx_supplier_invoice_order ON app_supplier_invoice (purchase_order_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_supplier_invoice_order ON app_supplier_invoice');
        $this->addSql('ALTER TABLE app_supplier_invoice ADD UNIQUE INDEX uniq_supplier_invoice_order (purchase_order_id)');
        $this->addSql('ALTER TABLE app_purchase_order_line DROP COLUMN invoiced_quantity');
    }
}
