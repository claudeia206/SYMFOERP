<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030224000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reconciliation matching reference to accounting entry lines.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_accounting_entry_line ADD matching_code VARCHAR(40) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_accounting_line_matching ON app_accounting_entry_line (matching_code)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_accounting_line_matching ON app_accounting_entry_line');
        $this->addSql('ALTER TABLE app_accounting_entry_line DROP matching_code');
    }
}
