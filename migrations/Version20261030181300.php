<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030181300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create product catalogue and auditable stock movements.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE app_product (
                id INT AUTO_INCREMENT NOT NULL,
                sku VARCHAR(64) NOT NULL,
                name VARCHAR(180) NOT NULL,
                description LONGTEXT DEFAULT NULL,
                unit VARCHAR(40) NOT NULL,
                purchase_price NUMERIC(12, 2) NOT NULL,
                sale_price NUMERIC(12, 2) NOT NULL,
                vat_rate NUMERIC(5, 2) NOT NULL,
                stock_tracked TINYINT(1) NOT NULL DEFAULT 1,
                stock_quantity NUMERIC(12, 3) NOT NULL DEFAULT 0,
                stock_alert_threshold NUMERIC(12, 3) NOT NULL DEFAULT 0,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_product_sku (sku),
                INDEX idx_product_active_name (active, name),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE app_product_stock_movement (
                id INT AUTO_INCREMENT NOT NULL,
                product_id INT NOT NULL,
                quantity NUMERIC(12, 3) NOT NULL,
                reason VARCHAR(40) NOT NULL,
                note LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_product_stock_movement_product_date (product_id, created_at),
                PRIMARY KEY(id),
                CONSTRAINT fk_product_stock_movement_product FOREIGN KEY (product_id) REFERENCES app_product (id) ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_product_stock_movement');
        $this->addSql('DROP TABLE app_product');
    }
}
