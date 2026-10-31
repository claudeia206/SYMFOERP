<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101004000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Require an acquisition counterpart and link fixed assets to their acquisition journal entry';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_fixed_asset ADD acquisition_counterpart_account_id INT DEFAULT NULL, ADD acquisition_journal_entry_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE app_fixed_asset ADD CONSTRAINT fk_fixed_asset_acquisition_counterpart FOREIGN KEY (acquisition_counterpart_account_id) REFERENCES app_accounting_account (id) ON DELETE RESTRICT, ADD CONSTRAINT fk_fixed_asset_acquisition_entry FOREIGN KEY (acquisition_journal_entry_id) REFERENCES app_journal_entry (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_fixed_asset DROP FOREIGN KEY fk_fixed_asset_acquisition_counterpart, DROP FOREIGN KEY fk_fixed_asset_acquisition_entry');
        $this->addSql('ALTER TABLE app_fixed_asset DROP acquisition_counterpart_account_id, DROP acquisition_journal_entry_id');
    }
}
