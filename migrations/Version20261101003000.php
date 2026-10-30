<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101003000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist fiscal-year closure state for accounting posting protection';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE app_accounting_fiscal_year (fiscal_year SMALLINT NOT NULL, closed_at DATETIME DEFAULT NULL, closed_by VARCHAR(180) DEFAULT NULL, PRIMARY KEY(fiscal_year)) DEFAULT CHARACTER SET utf8mb4 ENGINE=InnoDB");
        $values = [];
        for ($year = 2000; $year <= 2100; ++$year) {
            $values[] = '(' . $year . ')';
        }
        $this->addSql('INSERT INTO app_accounting_fiscal_year (fiscal_year) VALUES ' . implode(',', $values));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_accounting_fiscal_year');
    }
}
