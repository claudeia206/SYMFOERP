<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030181000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store explicit payment and late-payment terms for invoice issuance.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_company_profile ADD payment_terms VARCHAR(255) DEFAULT NULL, ADD late_penalty_rate VARCHAR(40) DEFAULT NULL, ADD recovery_fee VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_company_profile DROP payment_terms, DROP late_penalty_rate, DROP recovery_fee');
    }
}
