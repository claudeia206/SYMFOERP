<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add invoice payment records for issued invoices.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE app_sales_payment (
                id INT AUTO_INCREMENT NOT NULL,
                invoice_id INT NOT NULL,
                amount NUMERIC(12, 2) NOT NULL,
                paid_at DATE NOT NULL,
                method VARCHAR(20) NOT NULL,
                reference VARCHAR(120) DEFAULT NULL,
                note LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_sales_payment_invoice_date (invoice_id, paid_at),
                PRIMARY KEY(id),
                CONSTRAINT fk_sales_payment_invoice FOREIGN KEY (invoice_id) REFERENCES app_sales_invoice (id) ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_sales_payment');
    }
}
