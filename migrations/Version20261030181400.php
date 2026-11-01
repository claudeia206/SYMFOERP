<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030181400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Link sales quote, order and invoice lines to catalogue products.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_sales_quote_line ADD product_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE app_sales_order_line ADD product_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE app_sales_invoice_line ADD product_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE app_sales_quote_line ADD CONSTRAINT fk_sales_quote_line_product FOREIGN KEY (product_id) REFERENCES app_product (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE app_sales_order_line ADD CONSTRAINT fk_sales_order_line_product FOREIGN KEY (product_id) REFERENCES app_product (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE app_sales_invoice_line ADD CONSTRAINT fk_sales_invoice_line_product FOREIGN KEY (product_id) REFERENCES app_product (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX idx_sales_quote_line_product ON app_sales_quote_line (product_id)');
        $this->addSql('CREATE INDEX idx_sales_order_line_product ON app_sales_order_line (product_id)');
        $this->addSql('CREATE INDEX idx_sales_invoice_line_product ON app_sales_invoice_line (product_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_sales_invoice_line_product ON app_sales_invoice_line');
        $this->addSql('DROP INDEX idx_sales_order_line_product ON app_sales_order_line');
        $this->addSql('DROP INDEX idx_sales_quote_line_product ON app_sales_quote_line');
        $this->addSql('ALTER TABLE app_sales_invoice_line DROP FOREIGN KEY fk_sales_invoice_line_product, DROP product_id');
        $this->addSql('ALTER TABLE app_sales_order_line DROP FOREIGN KEY fk_sales_order_line_product, DROP product_id');
        $this->addSql('ALTER TABLE app_sales_quote_line DROP FOREIGN KEY fk_sales_quote_line_product, DROP product_id');
    }
}
