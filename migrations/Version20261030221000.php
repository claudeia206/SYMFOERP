<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261030221000 extends AbstractMigration {
 public function getDescription():string{return 'Seed standard French accounting journals, a 2026 fiscal year and starter PCG accounts.';}
 public function up(Schema $schema):void{
  $this->addSql("INSERT INTO app_accounting_journal (code,label,active) VALUES ('VE','Ventes',1),('AC','Achats',1),('BQ','Banque',1),('CA','Caisse',1),('OD','Opérations diverses',1)");
  $this->addSql("INSERT INTO app_accounting_fiscal_year (code,starts_on,ends_on,closed) VALUES ('2026','2026-01-01','2026-12-31',0)");
  $this->addSql("INSERT INTO app_accounting_account (number,label,class,active) VALUES ('101','Capital','1',1),('164','Emprunts auprès des établissements de crédit','1',1),('2183','Matériel de bureau et matériel informatique','2',1),('28183','Amortissements du matériel informatique','2',1),('401','Fournisseurs','4',1),('411','Clients','4',1),('44562','TVA déductible sur immobilisations','4',1),('44566','TVA déductible sur autres biens et services','4',1),('44571','TVA collectée','4',1),('512','Banques','5',1),('530','Caisse','5',1),('607','Achats de marchandises','6',1),('613','Locations','6',1),('641','Rémunérations du personnel','6',1),('706','Prestations de services','7',1),('707','Ventes de marchandises','7',1)");
 }
 public function down(Schema $schema):void{
  $this->addSql("DELETE FROM app_accounting_entry_line WHERE account_id IN (SELECT id FROM app_accounting_account WHERE number IN ('101','164','2183','28183','401','411','44562','44566','44571','512','530','607','613','641','706','707'))");
  $this->addSql("DELETE FROM app_accounting_account WHERE number IN ('101','164','2183','28183','401','411','44562','44566','44571','512','530','607','613','641','706','707')");
  $this->addSql("DELETE FROM app_accounting_fiscal_year WHERE code='2026'");
  $this->addSql("DELETE FROM app_accounting_journal WHERE code IN ('VE','AC','BQ','CA','OD')");
 }
}