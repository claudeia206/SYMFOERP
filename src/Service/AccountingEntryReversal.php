<?php

declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;

final class AccountingEntryReversal
{
    /**
     * @param list<array{account_id: int|string, label: string, debit: string, credit: string}> $lines
     * @return list<array{account_id: int, label: string, debit: string, credit: string}>
     */
    public static function reverseLines(array $lines): array
    {
        if (count($lines) < 2 || count($lines) > 100) {
            throw new InvalidArgumentException('Une contrepassation exige entre 2 et 100 lignes.');
        }

        $debitTotal = 0;
        $creditTotal = 0;
        $reversed = [];

        foreach ($lines as $line) {
            if (!isset($line['account_id'], $line['label'], $line['debit'], $line['credit'])) {
                throw new InvalidArgumentException('Ligne comptable incomplète.');
            }

            $accountId = filter_var($line['account_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $label = trim($line['label']);
            if ($accountId === false || $label === '' || mb_strlen($label) > 255) {
                throw new InvalidArgumentException('Compte ou libellé de ligne invalide.');
            }

            $debit = AccountingAmount::toCents($line['debit']);
            $credit = AccountingAmount::toCents($line['credit']);
            if (($debit > 0) === ($credit > 0)) {
                throw new InvalidArgumentException('Chaque ligne doit porter un montant positif sur un seul côté.');
            }

            $debitTotal = AccountingAmount::addCents($debitTotal, $debit);
            $creditTotal = AccountingAmount::addCents($creditTotal, $credit);
            $reversed[] = [
                'account_id' => $accountId,
                'label' => mb_substr('Contrepassation – '.$label, 0, 255),
                'debit' => AccountingAmount::formatCents($credit),
                'credit' => AccountingAmount::formatCents($debit),
            ];
        }

        if ($debitTotal <= 0 || $debitTotal !== $creditTotal) {
            throw new InvalidArgumentException('Impossible de contrepasser une écriture déséquilibrée.');
        }

        return $reversed;
    }
}
