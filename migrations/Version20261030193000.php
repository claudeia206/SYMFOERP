<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261030193000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add bank statement transactions and invoice payment reconciliation.'; }
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE app_bank_transaction (
 id INT AUTO_INCREMENT NOT NULL,
 transaction_date DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
 label VARCHAR(255) NOT NULL,
 amount NUMERIC(12, 2) NOT NULL,
 reference VARCHAR(120) DEFAULT NULL,
 fingerprint CHAR(64) NOT NULL,
 matched_sales_payment_id INT DEFAULT NULL,
 matched_supplier_payment_id INT DEFAULT NULL,
 imported_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
 UNIQUE INDEX uniq_bank_transaction_fingerprint (fingerprint),
 UNIQUE INDEX uniq_bank_transaction_sales_payment (matched_sales_payment_id),
 UNIQUE INDEX uniq_bank_transaction_supplier_payment (matched_supplier_payment_id),
 INDEX idx_bank_transaction_date (transaction_date),
 PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL);
        $this->addSql('ALTER TABLE app_bank_transaction ADD CONSTRAINT fk_bank_transaction_sales_payment FOREIGN KEY (matched_sales_payment_id) REFERENCES app_sales_payment (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE app_bank_transaction ADD CONSTRAINT fk_bank_transaction_supplier_payment FOREIGN KEY (matched_supplier_payment_id) REFERENCES app_supplier_payment (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE app_bank_transaction ADD CONSTRAINT chk_bank_transaction_one_match CHECK (matched_sales_payment_id IS NULL OR matched_supplier_payment_id IS NULL)');
        $this->addSql('ALTER TABLE app_bank_transaction ADD CONSTRAINT chk_bank_transaction_nonzero CHECK (amount <> 0)');
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE app_bank_transaction'); }
}
