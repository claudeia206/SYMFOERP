<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261030201000 extends AbstractMigration
{
    public function getDescription(): string { return 'Track source documents already posted to the general ledger.'; }
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE app_accounting_source_posting (
 id INT AUTO_INCREMENT NOT NULL,
 source_type VARCHAR(32) NOT NULL,
 source_id INT NOT NULL,
 entry_id INT NOT NULL,
 posted_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
 UNIQUE INDEX uniq_accounting_source (source_type, source_id),
 UNIQUE INDEX uniq_accounting_source_entry (entry_id),
 PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL);
        $this->addSql('ALTER TABLE app_accounting_source_posting ADD CONSTRAINT fk_accounting_source_entry FOREIGN KEY (entry_id) REFERENCES app_journal_entry (id) ON DELETE RESTRICT');
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE app_accounting_source_posting'); }
}
