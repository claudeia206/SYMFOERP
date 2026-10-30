<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261030192000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add customer credit notes and credited quantities on sales invoice lines.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_sales_invoice_line ADD credited_quantity NUMERIC(10, 3) DEFAULT 0.000 NOT NULL');
        $this->addSql(<<<'SQL'
CREATE TABLE app_sales_credit_note (
 id INT AUTO_INCREMENT NOT NULL, invoice_id INT NOT NULL, number VARCHAR(32) DEFAULT NULL,
 credit_date DATE NOT NULL COMMENT '(DC2Type:date_immutable)', reason LONGTEXT NOT NULL,
 seller_snapshot JSON NOT NULL, customer_snapshot JSON NOT NULL,
 created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
 UNIQUE INDEX uniq_sales_credit_note_number (number), INDEX idx_sales_credit_note_invoice_date (invoice_id, credit_date),
 PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL);
        $this->addSql('ALTER TABLE app_sales_credit_note ADD CONSTRAINT fk_sales_credit_note_invoice FOREIGN KEY (invoice_id) REFERENCES app_sales_invoice (id) ON DELETE RESTRICT');
        $this->addSql(<<<'SQL'
CREATE TABLE app_sales_credit_note_line (
 id INT AUTO_INCREMENT NOT NULL, credit_note_id INT NOT NULL, invoice_line_id INT NOT NULL,
 description VARCHAR(255) NOT NULL, quantity NUMERIC(10, 3) NOT NULL, unit_price NUMERIC(12, 2) NOT NULL,
 vat_rate NUMERIC(5, 2) NOT NULL, INDEX idx_sales_credit_note_line_note (credit_note_id),
 INDEX idx_sales_credit_note_line_invoice_line (invoice_line_id), PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL);
        $this->addSql('ALTER TABLE app_sales_credit_note_line ADD CONSTRAINT fk_sales_credit_note_line_note FOREIGN KEY (credit_note_id) REFERENCES app_sales_credit_note (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE app_sales_credit_note_line ADD CONSTRAINT fk_sales_credit_note_line_invoice_line FOREIGN KEY (invoice_line_id) REFERENCES app_sales_invoice_line (id) ON DELETE RESTRICT');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_sales_credit_note_line'); $this->addSql('DROP TABLE app_sales_credit_note');
        $this->addSql('ALTER TABLE app_sales_invoice_line DROP credited_quantity');
    }
}
