<?php
declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use RuntimeException;

final class AccountingPeriodGuard
{
    public function assertOpenDate(Connection $db, string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new RuntimeException('Date de pièce comptable invalide.');
        }

        $year = (int) $parsed->format('Y');
        if ($year < 2000 || $year > 2100) {
            throw new RuntimeException('Exercice comptable hors limites.');
        }

        $period = $db->fetchAssociative(
            'SELECT fiscal_year, closed_at FROM app_accounting_fiscal_year WHERE fiscal_year = ? FOR UPDATE',
            [$year]
        );
        if (!$period) {
            throw new RuntimeException('Exercice comptable non initialisé.');
        }
        if ($period['closed_at'] !== null) {
            throw new RuntimeException(sprintf('Exercice %d clôturé : aucune nouvelle écriture autorisée.', $year));
        }
    }
}
