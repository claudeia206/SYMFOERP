<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add legal company profile, customer invoice addresses and invoice issuance snapshots/sequences.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE app_company_profile (
                id INT NOT NULL,
                legal_name VARCHAR(180) NOT NULL,
                address VARCHAR(255) NOT NULL,
                postal_code VARCHAR(20) NOT NULL,
                city VARCHAR(120) NOT NULL,
                country VARCHAR(80) NOT NULL,
                siren VARCHAR(9) DEFAULT NULL,
                siret VARCHAR(14) DEFAULT NULL,
                vat_number VARCHAR(20) DEFAULT NULL,
                legal_form VARCHAR(120) DEFAULT NULL,
                share_capital VARCHAR(80) DEFAULT NULL,
                rcs_city VARCHAR(120) DEFAULT NULL,
                email VARCHAR(180) DEFAULT NULL,
                phone VARCHAR(40) DEFAULT NULL,
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE app_third_party
                ADD address VARCHAR(255) DEFAULT NULL,
                ADD postal_code VARCHAR(20) DEFAULT NULL,
                ADD city VARCHAR(120) DEFAULT NULL,
                ADD country VARCHAR(80) DEFAULT NULL
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE app_sales_invoice
                ADD issued_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                ADD seller_snapshot JSON DEFAULT NULL,
                ADD customer_snapshot JSON DEFAULT NULL
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE app_invoice_sequence (
                fiscal_year INT NOT NULL,
                last_number INT NOT NULL,
                PRIMARY KEY(fiscal_year)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_invoice_sequence');
        $this->addSql('ALTER TABLE app_sales_invoice DROP issued_at, DROP seller_snapshot, DROP customer_snapshot');
        $this->addSql('ALTER TABLE app_third_party DROP address, DROP postal_code, DROP city, DROP country');
        $this->addSql('DROP TABLE app_company_profile');
    }
}
