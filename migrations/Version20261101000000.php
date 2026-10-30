<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261101000000 extends AbstractMigration
{
 public function getDescription(): string { return 'Create fixed asset register'; }
 public function up(Schema $schema): void {
  $this->addSql("CREATE TABLE app_fixed_asset (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, acquisition_cost NUMERIC(15,2) NOT NULL, residual_value NUMERIC(15,2) NOT NULL DEFAULT 0, useful_life_years SMALLINT NOT NULL, acquisition_date DATE NOT NULL, in_service_date DATE NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'active', created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_FIXED_ASSET_CODE (code), INDEX IDX_FIXED_ASSET_STATUS (status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 ENGINE=InnoDB");
 }
 public function down(Schema $schema): void { $this->addSql('DROP TABLE app_fixed_asset'); }
}