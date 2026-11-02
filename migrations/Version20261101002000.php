<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101002000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add fixed asset disposal accounting configuration and history'; }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE app_fixed_asset ADD disposal_bank_account_id INT DEFAULT NULL, ADD disposal_gain_account_id INT DEFAULT NULL, ADD disposal_loss_account_id INT DEFAULT NULL, ADD disposal_date DATE DEFAULT NULL, ADD disposal_proceeds NUMERIC(15,2) DEFAULT NULL, ADD disposal_journal_entry_id INT DEFAULT NULL");
        $this->addSql("ALTER TABLE app_fixed_asset ADD CONSTRAINT fk_fixed_asset_disposal_bank FOREIGN KEY (disposal_bank_account_id) REFERENCES app_accounting_account (id) ON DELETE RESTRICT, ADD CONSTRAINT fk_fixed_asset_disposal_gain FOREIGN KEY (disposal_gain_account_id) REFERENCES app_accounting_account (id) ON DELETE RESTRICT, ADD CONSTRAINT fk_fixed_asset_disposal_loss FOREIGN KEY (disposal_loss_account_id) REFERENCES app_accounting_account (id) ON DELETE RESTRICT, ADD CONSTRAINT fk_fixed_asset_disposal_entry FOREIGN KEY (disposal_journal_entry_id) REFERENCES app_journal_entry (id) ON DELETE RESTRICT");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE app_fixed_asset DROP FOREIGN KEY fk_fixed_asset_disposal_bank, DROP FOREIGN KEY fk_fixed_asset_disposal_gain, DROP FOREIGN KEY fk_fixed_asset_disposal_loss, DROP FOREIGN KEY fk_fixed_asset_disposal_entry");
        $this->addSql("ALTER TABLE app_fixed_asset DROP disposal_bank_account_id, DROP disposal_gain_account_id, DROP disposal_loss_account_id, DROP disposal_date, DROP disposal_proceeds, DROP disposal_journal_entry_id");
    }
}