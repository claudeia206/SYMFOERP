<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Link accounting journal reversals to their original entries and prevent duplicate reversals.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_journal_entry ADD reversal_of_entry_id INT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_journal_entry_reversal ON app_journal_entry (reversal_of_entry_id)');
        $this->addSql('ALTER TABLE app_journal_entry ADD CONSTRAINT fk_journal_entry_reversal FOREIGN KEY (reversal_of_entry_id) REFERENCES app_journal_entry (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_journal_entry DROP FOREIGN KEY fk_journal_entry_reversal');
        $this->addSql('DROP INDEX uniq_journal_entry_reversal ON app_journal_entry');
        $this->addSql('ALTER TABLE app_journal_entry DROP reversal_of_entry_id');
    }
}
