<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add result accounts and track annual closing and opening journal entries.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT IGNORE INTO app_accounting_account (code, name, account_class, is_active, created_at) VALUES ('120000', 'Résultat de l’exercice – bénéfice', 1, 1, NOW()), ('129000', 'Résultat de l’exercice – perte', 1, 1, NOW())");
        $this->addSql('ALTER TABLE app_accounting_fiscal_year ADD closing_entry_id INT DEFAULT NULL, ADD opening_entry_id INT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_accounting_fiscal_closing_entry ON app_accounting_fiscal_year (closing_entry_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_accounting_fiscal_opening_entry ON app_accounting_fiscal_year (opening_entry_id)');
        $this->addSql('ALTER TABLE app_accounting_fiscal_year ADD CONSTRAINT fk_accounting_fiscal_closing_entry FOREIGN KEY (closing_entry_id) REFERENCES app_journal_entry (id) ON DELETE RESTRICT, ADD CONSTRAINT fk_accounting_fiscal_opening_entry FOREIGN KEY (opening_entry_id) REFERENCES app_journal_entry (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_accounting_fiscal_year DROP FOREIGN KEY fk_accounting_fiscal_closing_entry, DROP FOREIGN KEY fk_accounting_fiscal_opening_entry');
        $this->addSql('DROP INDEX uniq_accounting_fiscal_closing_entry ON app_accounting_fiscal_year');
        $this->addSql('DROP INDEX uniq_accounting_fiscal_opening_entry ON app_accounting_fiscal_year');
        $this->addSql('ALTER TABLE app_accounting_fiscal_year DROP closing_entry_id, DROP opening_entry_id');
        $this->addSql("DELETE FROM app_accounting_account WHERE code IN ('120000', '129000') AND id NOT IN (SELECT account_id FROM app_journal_entry_line)");
    }
}
