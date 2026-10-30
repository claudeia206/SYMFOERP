<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030223000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add clearing accounts for cheques, card receipts and unclassified payment methods.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO app_accounting_account (number, label, class, active) VALUES ('471', 'Comptes d’attente - règlements à affecter', '4', 1), ('5112', 'Chèques à encaisser', '5', 1), ('5115', 'Cartes de crédit à encaisser', '5', 1)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM app_accounting_account WHERE number IN ('471', '5112', '5115')");
    }
}
