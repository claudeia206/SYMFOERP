<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

final class AccountingEntryValidator
{
    /**
     * @param array<array{account: string, label: string, debit: int, credit: int}> $lines
     * @return array{debit: int, credit: int}
     */
    public static function validate(array $lines): array
    {
        if ($lines === []) {
            throw new RuntimeException('Une écriture comptable doit contenir au moins une ligne.');
        }

        $debit = 0;
        $credit = 0;

        foreach ($lines as $line) {
            if (
                !is_array($line)
                || !isset($line['account'], $line['label'], $line['debit'], $line['credit'])
                || !is_string($line['account'])
                || trim($line['account']) === ''
                || !is_string($line['label'])
                || trim($line['label']) === ''
            ) {
                throw new RuntimeException('Ligne de comptabilisation automatique invalide.');
            }

            $lineDebit = $line['debit'];
            $lineCredit = $line['credit'];
            if (
                !is_int($lineDebit)
                || !is_int($lineCredit)
                || $lineDebit < 0
                || $lineCredit < 0
                || (($lineDebit > 0) === ($lineCredit > 0))
            ) {
                throw new RuntimeException('Montants de ligne comptable invalides.');
            }

            $debit = AccountingAmount::addCents($debit, $lineDebit);
            $credit = AccountingAmount::addCents($credit, $lineCredit);
        }

        if ($debit <= 0 || $debit !== $credit) {
            throw new RuntimeException('Écriture automatique déséquilibrée.');
        }

        return ['debit' => $debit, 'credit' => $credit];
    }
}
