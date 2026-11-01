<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030181100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the seller VAT regime wording printed on issued invoices.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_company_profile ADD vat_regime_note VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_company_profile DROP vat_regime_note');
    }
}
