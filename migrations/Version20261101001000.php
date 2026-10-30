<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101001000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add accounting mappings and posted depreciation history for fixed assets'; }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE app_fixed_asset ADD asset_account_id INT DEFAULT NULL, ADD accumulated_account_id INT DEFAULT NULL, ADD expense_account_id INT DEFAULT NULL, ADD journal_id INT DEFAULT NULL");
        $this->addSql("ALTER TABLE app_fixed_asset ADD CONSTRAINT fk_fixed_asset_asset_account FOREIGN KEY (asset_account_id) REFERENCES app_accounting_account (id) ON DELETE RESTRICT, ADD CONSTRAINT fk_fixed_asset_accum_account FOREIGN KEY (accumulated_account_id) REFERENCES app_accounting_account (id) ON DELETE RESTRICT, ADD CONSTRAINT fk_fixed_asset_expense_account FOREIGN KEY (expense_account_id) REFERENCES app_accounting_account (id) ON DELETE RESTRICT, ADD CONSTRAINT fk_fixed_asset_journal FOREIGN KEY (journal_id) REFERENCES app_accounting_journal (id) ON DELETE RESTRICT");
        $this->addSql("CREATE TABLE app_fixed_asset_depreciation (id INT AUTO_INCREMENT NOT NULL, asset_id INT NOT NULL, fiscal_year SMALLINT NOT NULL, amount NUMERIC(15,2) NOT NULL, journal_entry_id INT NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_asset_depreciation_year (asset_id, fiscal_year), UNIQUE INDEX uniq_asset_depreciation_entry (journal_entry_id), PRIMARY KEY(id), CONSTRAINT fk_asset_depreciation_asset FOREIGN KEY (asset_id) REFERENCES app_fixed_asset (id) ON DELETE RESTRICT, CONSTRAINT fk_asset_depreciation_entry FOREIGN KEY (journal_entry_id) REFERENCES app_journal_entry (id) ON DELETE RESTRICT) DEFAULT CHARACTER SET utf8mb4 ENGINE=InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_fixed_asset_depreciation');
        $this->addSql('ALTER TABLE app_fixed_asset DROP FOREIGN KEY fk_fixed_asset_asset_account, DROP FOREIGN KEY fk_fixed_asset_accum_account, DROP FOREIGN KEY fk_fixed_asset_expense_account, DROP FOREIGN KEY fk_fixed_asset_journal');
        $this->addSql('ALTER TABLE app_fixed_asset DROP asset_account_id, DROP accumulated_account_id, DROP expense_account_id, DROP journal_id');
    }
}