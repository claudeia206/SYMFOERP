<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030181200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Configure payment delay days used to calculate invoice due dates.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_company_profile ADD payment_delay_days INT NOT NULL DEFAULT 30');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_company_profile DROP payment_delay_days');
    }
}
