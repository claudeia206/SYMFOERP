<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create draft sales invoices and immutable copies of order lines.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE app_sales_invoice (
                id INT AUTO_INCREMENT NOT NULL,
                sales_order_id INT NOT NULL,
                third_party_id INT NOT NULL,
                number VARCHAR(32) DEFAULT NULL,
                status VARCHAR(20) NOT NULL,
                invoice_date DATE NOT NULL,
                due_date DATE NOT NULL,
                notes LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_sales_invoice_number (number),
                UNIQUE INDEX uniq_sales_invoice_order (sales_order_id),
                INDEX idx_sales_invoice_customer_status (third_party_id, status),
                PRIMARY KEY(id),
                CONSTRAINT fk_sales_invoice_order FOREIGN KEY (sales_order_id) REFERENCES app_sales_order (id) ON DELETE RESTRICT,
                CONSTRAINT fk_sales_invoice_customer FOREIGN KEY (third_party_id) REFERENCES app_third_party (id) ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE app_sales_invoice_line (
                id INT AUTO_INCREMENT NOT NULL,
                invoice_id INT NOT NULL,
                description VARCHAR(255) NOT NULL,
                quantity NUMERIC(10, 3) NOT NULL,
                unit_price NUMERIC(12, 2) NOT NULL,
                vat_rate NUMERIC(5, 2) NOT NULL,
                position INT NOT NULL,
                INDEX idx_sales_invoice_line_invoice (invoice_id, position),
                PRIMARY KEY(id),
                CONSTRAINT fk_sales_invoice_line_invoice FOREIGN KEY (invoice_id) REFERENCES app_sales_invoice (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_sales_invoice_line');
        $this->addSql('DROP TABLE app_sales_invoice');
    }
}
