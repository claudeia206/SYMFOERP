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
        // Existing invoices did not retain a purchase-order-line foreign key.
        // Allocate their snapshotted quantities to matching order lines by position,
        // preserving the aggregate invoiced quantity for each product and order.
        $this->addSql(<<<'SQL'
            UPDATE app_purchase_order_line pol
            INNER JOIN (
                SELECT allocation.order_line_id,
                       LEAST(
                           allocation.received_quantity,
                           GREATEST(0, allocation.invoice_quantity - (allocation.cumulative_received - allocation.received_quantity))
                       ) AS allocated_quantity
                FROM (
                    SELECT pol.id AS order_line_id,
                           pol.received_quantity,
                           COALESCE(invoice_totals.invoice_quantity, 0) AS invoice_quantity,
                           SUM(pol.received_quantity) OVER (
                               PARTITION BY pol.purchase_order_id, pol.product_id, pol.unit_price, pol.vat_rate
                               ORDER BY pol.position, pol.id
                               ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                           ) AS cumulative_received
                    FROM app_purchase_order_line pol
                    LEFT JOIN (
                        SELECT si.purchase_order_id, sil.product_id, sil.unit_price, sil.vat_rate, SUM(sil.quantity) AS invoice_quantity
                        FROM app_supplier_invoice si
                        INNER JOIN app_supplier_invoice_line sil ON sil.invoice_id = si.id
                        GROUP BY si.purchase_order_id, sil.product_id, sil.unit_price, sil.vat_rate
                    ) invoice_totals
                      ON invoice_totals.purchase_order_id = pol.purchase_order_id
                     AND invoice_totals.product_id = pol.product_id
                     AND invoice_totals.unit_price = pol.unit_price
                     AND invoice_totals.vat_rate = pol.vat_rate
                ) allocation
            ) allocated ON allocated.order_line_id = pol.id
            SET pol.invoiced_quantity = allocated.allocated_quantity
            SQL);
        // Add the replacement index before dropping the unique index: InnoDB
        // requires an index for the purchase_order_id foreign key at every step.
        $this->addSql('CREATE INDEX idx_supplier_invoice_order ON app_supplier_invoice (purchase_order_id)');
        $this->addSql('ALTER TABLE app_supplier_invoice DROP INDEX uniq_supplier_invoice_order');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_supplier_invoice_order ON app_supplier_invoice');
        $this->addSql('ALTER TABLE app_supplier_invoice ADD UNIQUE INDEX uniq_supplier_invoice_order (purchase_order_id)');
        $this->addSql('ALTER TABLE app_purchase_order_line DROP COLUMN invoiced_quantity');
    }
}
