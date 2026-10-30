<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261030200000 extends AbstractMigration
{
    public function getDescription(): string { return 'Create general ledger chart of accounts, journals and balanced journal entries.'; }
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE app_accounting_account (
 id INT AUTO_INCREMENT NOT NULL,
 code VARCHAR(12) NOT NULL,
 name VARCHAR(180) NOT NULL,
 account_class SMALLINT NOT NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
 UNIQUE INDEX uniq_accounting_account_code (code),
 INDEX idx_accounting_account_class (account_class),
 PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE app_accounting_journal (
 id INT AUTO_INCREMENT NOT NULL,
 code VARCHAR(8) NOT NULL,
 name VARCHAR(120) NOT NULL,
 UNIQUE INDEX uniq_accounting_journal_code (code),
 PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE app_journal_entry (
 id INT AUTO_INCREMENT NOT NULL,
 journal_id INT NOT NULL,
 entry_number INT NOT NULL,
 entry_date DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
 reference VARCHAR(120) DEFAULT NULL,
 description VARCHAR(255) NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'posted',
 created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
 UNIQUE INDEX uniq_journal_entry_number (journal_id, entry_number),
 INDEX idx_journal_entry_date (entry_date),
 INDEX idx_journal_entry_status_date (status, entry_date),
 PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE app_journal_entry_line (
 id INT AUTO_INCREMENT NOT NULL,
 entry_id INT NOT NULL,
 account_id INT NOT NULL,
 label VARCHAR(255) NOT NULL,
 debit NUMERIC(14, 2) NOT NULL DEFAULT 0.00,
 credit NUMERIC(14, 2) NOT NULL DEFAULT 0.00,
 INDEX idx_journal_line_entry (entry_id, id),
 INDEX idx_journal_line_account (account_id, entry_id),
 PRIMARY KEY(id),
 CONSTRAINT chk_journal_line_nonnegative CHECK (debit >= 0 AND credit >= 0),
 CONSTRAINT chk_journal_line_one_side CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0))
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL);
        $this->addSql('ALTER TABLE app_journal_entry ADD CONSTRAINT fk_journal_entry_journal FOREIGN KEY (journal_id) REFERENCES app_accounting_journal (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE app_journal_entry_line ADD CONSTRAINT fk_journal_line_entry FOREIGN KEY (entry_id) REFERENCES app_journal_entry (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE app_journal_entry_line ADD CONSTRAINT fk_journal_line_account FOREIGN KEY (account_id) REFERENCES app_accounting_account (id) ON DELETE RESTRICT');
        foreach ([['VE','Ventes'],['AC','Achats'],['BQ','Banque'],['CA','Caisse'],['OD','Opérations diverses']] as [$code,$name]) {
            $this->addSql('INSERT INTO app_accounting_journal (code,name) VALUES (?,?)',[$code,$name]);
        }
        $accounts = [
            ['101000','Capital',1],['106100','Réserve légale',1],['164000','Emprunts auprès des établissements de crédit',1],
            ['215400','Matériel industriel',2],['218300','Matériel de bureau et informatique',2],['281540','Amortissements du matériel industriel',2],
            ['401000','Fournisseurs',4],['411000','Clients',4],['421000','Personnel – rémunérations dues',4],
            ['445620','TVA déductible sur immobilisations',4],['445660','TVA déductible sur autres biens et services',4],
            ['445710','TVA collectée',4],['445510','TVA à décaisser',4],['512000','Banque',5],['530000','Caisse',5],
            ['606100','Fournitures non stockables',6],['607000','Achats de marchandises',6],['613000','Locations',6],
            ['616000','Primes d’assurances',6],['621000','Personnel extérieur',6],['641000','Rémunérations du personnel',6],
            ['706000','Prestations de services',7],['707000','Ventes de marchandises',7],['708000','Produits des activités annexes',7],
            ['681120','Dotations aux amortissements',6],['758000','Produits divers de gestion courante',7],['658000','Charges diverses de gestion courante',6],
        ];
        foreach ($accounts as [$code,$name,$class]) {
            $this->addSql('INSERT INTO app_accounting_account (code,name,account_class,is_active,created_at) VALUES (?,?,?,1,NOW())',[$code,$name,$class]);
        }
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_journal_entry_line');
        $this->addSql('DROP TABLE app_journal_entry');
        $this->addSql('DROP TABLE app_accounting_journal');
        $this->addSql('DROP TABLE app_accounting_account');
    }
}
