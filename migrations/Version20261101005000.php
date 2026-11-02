<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101005000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track manually sent customer payment reminders.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE app_customer_payment_reminder (
    id INT AUTO_INCREMENT NOT NULL,
    invoice_id INT NOT NULL,
    reminder_level SMALLINT NOT NULL,
    channel VARCHAR(16) NOT NULL,
    recipient VARCHAR(180) DEFAULT NULL,
    note LONGTEXT DEFAULT NULL,
    sent_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    INDEX idx_customer_reminder_invoice_date (invoice_id, sent_at),
    INDEX idx_customer_reminder_sent_at (sent_at),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
SQL);
        $this->addSql('ALTER TABLE app_customer_payment_reminder ADD CONSTRAINT fk_customer_reminder_invoice FOREIGN KEY (invoice_id) REFERENCES app_sales_invoice (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE app_customer_payment_reminder ADD CONSTRAINT chk_customer_reminder_level CHECK (reminder_level BETWEEN 1 AND 3)');
        $this->addSql("ALTER TABLE app_customer_payment_reminder ADD CONSTRAINT chk_customer_reminder_channel CHECK (channel IN ('email','phone','letter'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_customer_payment_reminder');
    }
}
