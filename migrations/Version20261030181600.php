<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030181600 extends AbstractMigration
{
    public function getDescription(): string { return 'Create supplier invoices and outgoing supplier payments.'; }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE app_supplier_invoice (id INT AUTO_INCREMENT NOT NULL, purchase_order_id INT NOT NULL, supplier_id INT NOT NULL, supplier_invoice_number VARCHAR(120) NOT NULL, status VARCHAR(24) NOT NULL, invoice_date DATE NOT NULL COMMENT '(DC2Type:date_immutable)', due_date DATE NOT NULL COMMENT '(DC2Type:date_immutable)', notes LONGTEXT DEFAULT NULL, received_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX uniq_supplier_invoice_order (purchase_order_id), UNIQUE INDEX uniq_supplier_invoice_reference (supplier_id, supplier_invoice_number), INDEX idx_supplier_invoice_status_due (status, due_date), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE app_supplier_invoice ADD CONSTRAINT fk_supplier_invoice_order FOREIGN KEY (purchase_order_id) REFERENCES app_purchase_order (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE app_supplier_invoice ADD CONSTRAINT fk_supplier_invoice_supplier FOREIGN KEY (supplier_id) REFERENCES app_third_party (id) ON DELETE RESTRICT');
        $this->addSql("CREATE TABLE app_supplier_invoice_line (id INT AUTO_INCREMENT NOT NULL, invoice_id INT NOT NULL, product_id INT NOT NULL, description VARCHAR(255) NOT NULL, quantity NUMERIC(10, 3) NOT NULL, unit_price NUMERIC(12, 2) NOT NULL, vat_rate NUMERIC(5, 2) NOT NULL, position INT NOT NULL, INDEX idx_supplier_invoice_line_invoice (invoice_id, position), INDEX idx_supplier_invoice_line_product (product_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE app_supplier_invoice_line ADD CONSTRAINT fk_supplier_invoice_line_invoice FOREIGN KEY (invoice_id) REFERENCES app_supplier_invoice (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE app_supplier_invoice_line ADD CONSTRAINT fk_supplier_invoice_line_product FOREIGN KEY (product_id) REFERENCES app_product (id) ON DELETE RESTRICT');
        $this->addSql("CREATE TABLE app_supplier_payment (id INT AUTO_INCREMENT NOT NULL, invoice_id INT NOT NULL, amount NUMERIC(12, 2) NOT NULL, paid_at DATE NOT NULL COMMENT '(DC2Type:date_immutable)', method VARCHAR(20) NOT NULL, reference VARCHAR(120) DEFAULT NULL, note LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX idx_supplier_payment_invoice_date (invoice_id, paid_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE app_supplier_payment ADD CONSTRAINT fk_supplier_payment_invoice FOREIGN KEY (invoice_id) REFERENCES app_supplier_invoice (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_supplier_payment');
        $this->addSql('DROP TABLE app_supplier_invoice_line');
        $this->addSql('DROP TABLE app_supplier_invoice');
    }
}
