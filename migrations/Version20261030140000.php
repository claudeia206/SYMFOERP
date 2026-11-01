<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create sales quotes and their itemized lines.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE app_sales_quote (
                id INT AUTO_INCREMENT NOT NULL,
                third_party_id INT NOT NULL,
                number VARCHAR(32) DEFAULT NULL,
                status VARCHAR(20) NOT NULL,
                quote_date DATE NOT NULL,
                valid_until DATE NOT NULL,
                notes LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_sales_quote_number (number),
                INDEX idx_sales_quote_customer_status (third_party_id, status),
                PRIMARY KEY(id),
                CONSTRAINT fk_sales_quote_customer FOREIGN KEY (third_party_id) REFERENCES app_third_party (id) ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE app_sales_quote_line (
                id INT AUTO_INCREMENT NOT NULL,
                quote_id INT NOT NULL,
                description VARCHAR(255) NOT NULL,
                quantity NUMERIC(10, 3) NOT NULL,
                unit_price NUMERIC(12, 2) NOT NULL,
                vat_rate NUMERIC(5, 2) NOT NULL,
                position INT NOT NULL,
                INDEX idx_sales_quote_line_quote (quote_id, position),
                PRIMARY KEY(id),
                CONSTRAINT fk_sales_quote_line_quote FOREIGN KEY (quote_id) REFERENCES app_sales_quote (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_sales_quote_line');
        $this->addSql('DROP TABLE app_sales_quote');
    }
}
