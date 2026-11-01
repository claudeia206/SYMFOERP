<?php

declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;

final class AccountingYearClosingCalculator
{
    /**
     * @param list<array{account_id: int|string, code: string, name: string, account_class: int|string, debit: int, credit: int}> $balances
     * @return list<array{account_id: int, label: string, debit: int, credit: int}>
     */
    public static function closingLines(array $balances, int $profitAccountId, int $lossAccountId): array
    {
        if ($profitAccountId <= 0 || $lossAccountId <= 0 || $profitAccountId === $lossAccountId) {
            throw new InvalidArgumentException('Comptes de résultat de clôture invalides.');
        }

        $lines = [];
        $result = 0;
        foreach ($balances as $balance) {
            $class = (int) ($balance['account_class'] ?? 0);
            if ($class !== 6 && $class !== 7) {
                throw new InvalidArgumentException('La clôture du résultat ne concerne que les comptes des classes 6 et 7.');
            }

            $accountId = filter_var($balance['account_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $code = (string) ($balance['code'] ?? '');
            $name = trim((string) ($balance['name'] ?? ''));
            $debit = $balance['debit'] ?? null;
            $credit = $balance['credit'] ?? null;
            if ($accountId === false || $code === '' || $name === '' || !is_int($debit) || !is_int($credit) || $debit < 0 || $credit < 0) {
                throw new InvalidArgumentException('Solde de compte de résultat invalide.');
            }

            $net = AccountingAmount::addSignedCents($debit, -$credit);
            if ($net === 0) {
                continue;
            }

            $result = AccountingAmount::addSignedCents($result, $net);
            $lines[] = [
                'account_id' => $accountId,
                'label' => mb_substr('Clôture '.$code.' – '.$name, 0, 255),
                'debit' => $net < 0 ? abs($net) : 0,
                'credit' => $net > 0 ? $net : 0,
            ];
        }

        if ($result > 0) {
            $lines[] = [
                'account_id' => $lossAccountId,
                'label' => 'Résultat de clôture – perte',
                'debit' => $result,
                'credit' => 0,
            ];
        } elseif ($result < 0) {
            $lines[] = [
                'account_id' => $profitAccountId,
                'label' => 'Résultat de clôture – bénéfice',
                'debit' => 0,
                'credit' => abs($result),
            ];
        }

        self::assertBalanced($lines);

        return $lines;
    }

    /**
     * @param list<array{account_id: int|string, code: string, name: string, account_class: int|string, debit: int, credit: int}> $balances
     * @return list<array{account_id: int, label: string, debit: int, credit: int}>
     */
    public static function openingLines(array $balances): array
    {
        $lines = [];
        foreach ($balances as $balance) {
            $class = (int) ($balance['account_class'] ?? 0);
            if ($class < 1 || $class > 5) {
                throw new InvalidArgumentException('Les à-nouveaux ne peuvent reprendre que les comptes de bilan des classes 1 à 5.');
            }

            $accountId = filter_var($balance['account_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $code = (string) ($balance['code'] ?? '');
            $name = trim((string) ($balance['name'] ?? ''));
            $debit = $balance['debit'] ?? null;
            $credit = $balance['credit'] ?? null;
            if ($accountId === false || $code === '' || $name === '' || !is_int($debit) || !is_int($credit) || $debit < 0 || $credit < 0) {
                throw new InvalidArgumentException('Solde de compte de bilan invalide.');
            }

            $net = AccountingAmount::addSignedCents($debit, -$credit);
            if ($net === 0) {
                continue;
            }

            $lines[] = [
                'account_id' => $accountId,
                'label' => mb_substr('À-nouveaux '.$code.' – '.$name, 0, 255),
                'debit' => $net > 0 ? $net : 0,
                'credit' => $net < 0 ? abs($net) : 0,
            ];
        }

        self::assertBalanced($lines);

        return $lines;
    }

    /**
     * @param list<array{account_id: int, label: string, debit: int, credit: int}> $lines
     */
    private static function assertBalanced(array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $validatorLines = [];
        foreach ($lines as $line) {
            $validatorLines[] = [
                'account' => (string) $line['account_id'],
                'label' => $line['label'],
                'debit' => $line['debit'],
                'credit' => $line['credit'],
            ];
        }

        AccountingEntryValidator::validate($validatorLines);
    }
}
