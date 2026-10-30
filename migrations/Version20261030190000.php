<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261030190000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add customer delivery notes and per-order-line delivered quantities.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_sales_order_line ADD delivered_quantity NUMERIC(10, 3) DEFAULT 0.000 NOT NULL');
        $this->addSql("CREATE TABLE app_sales_delivery (id INT AUTO_INCREMENT NOT NULL, sales_order_id INT NOT NULL, number VARCHAR(32) DEFAULT NULL, delivery_date DATE NOT NULL COMMENT '(DC2Type:date_immutable)', notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX uniq_sales_delivery_number (number), INDEX idx_sales_delivery_order_date (sales_order_id, delivery_date), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE app_sales_delivery ADD CONSTRAINT fk_sales_delivery_order FOREIGN KEY (sales_order_id) REFERENCES app_sales_order (id) ON DELETE RESTRICT');
        $this->addSql("CREATE TABLE app_sales_delivery_line (id INT AUTO_INCREMENT NOT NULL, delivery_id INT NOT NULL, sales_order_line_id INT NOT NULL, description VARCHAR(255) NOT NULL, quantity NUMERIC(10, 3) NOT NULL, INDEX idx_sales_delivery_line_delivery (delivery_id), INDEX idx_sales_delivery_line_order_line (sales_order_line_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE app_sales_delivery_line ADD CONSTRAINT fk_sales_delivery_line_delivery FOREIGN KEY (delivery_id) REFERENCES app_sales_delivery (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE app_sales_delivery_line ADD CONSTRAINT fk_sales_delivery_line_order_line FOREIGN KEY (sales_order_line_id) REFERENCES app_sales_order_line (id) ON DELETE RESTRICT');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_sales_delivery_line'); $this->addSql('DROP TABLE app_sales_delivery');
        $this->addSql('ALTER TABLE app_sales_order_line DROP delivered_quantity');
    }
}
