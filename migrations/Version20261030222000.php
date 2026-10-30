<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261030222000 extends AbstractMigration
{
 public function getDescription():string{return 'Enforce one accounting entry per source document or payment.';}
 public function up(Schema $schema):void
 {
  $this->addSql('CREATE UNIQUE INDEX uniq_accounting_entry_source ON app_accounting_entry (source_type, source_id)');
 }
 public function down(Schema $schema):void
 {
  $this->addSql('DROP INDEX uniq_accounting_entry_source ON app_accounting_entry');
 }
}
