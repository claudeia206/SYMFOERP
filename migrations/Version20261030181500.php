<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030181500 extends AbstractMigration
{
    public function getDescription(): string { return 'Create supplier purchase orders and receipt quantities.'; }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE app_purchase_order (id INT AUTO_INCREMENT NOT NULL, supplier_id INT NOT NULL, number VARCHAR(32) DEFAULT NULL, status VARCHAR(24) NOT NULL, order_date DATE NOT NULL COMMENT '(DC2Type:date_immutable)', notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX uniq_purchase_order_number (number), INDEX idx_purchase_order_supplier_status (supplier_id, status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE app_purchase_order ADD CONSTRAINT fk_purchase_order_supplier FOREIGN KEY (supplier_id) REFERENCES app_third_party (id) ON DELETE RESTRICT');
        $this->addSql("CREATE TABLE app_purchase_order_line (id INT AUTO_INCREMENT NOT NULL, purchase_order_id INT NOT NULL, product_id INT NOT NULL, description VARCHAR(255) NOT NULL, quantity NUMERIC(10, 3) NOT NULL, received_quantity NUMERIC(10, 3) NOT NULL, unit_price NUMERIC(12, 2) NOT NULL, vat_rate NUMERIC(5, 2) NOT NULL, position INT NOT NULL, INDEX idx_purchase_order_line_order (purchase_order_id, position), INDEX idx_purchase_order_line_product (product_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE app_purchase_order_line ADD CONSTRAINT fk_purchase_order_line_order FOREIGN KEY (purchase_order_id) REFERENCES app_purchase_order (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE app_purchase_order_line ADD CONSTRAINT fk_purchase_order_line_product FOREIGN KEY (product_id) REFERENCES app_product (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_purchase_order_line');
        $this->addSql('DROP TABLE app_purchase_order');
    }
}
