<?php

declare(strict_types=1);

namespace App\\Service;

final class TrialBalanceCsvFormatter
{
    /**
     * @param list<array{code:string,name:string,account_class:string|int,opening_debit:string,opening_credit:string,period_debit:string,period_credit:string}> $accounts
     */
    public function format(array $accounts): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \\RuntimeException('Impossible de préparer le fichier CSV.');
        }

        fwrite($stream, "\\xEF\\xBB\\xBF");
        fputcsv($stream, [
            'Compte', 'Intitulé', 'Classe',
            'Solde antérieur débiteur', 'Solde antérieur créditeur',
            'Mouvements débit', 'Mouvements crédit',
            'Solde clôture débiteur', 'Solde clôture créditeur',
        ], ';', '"', '');

        $totals = [
            'opening_debit' => 0,
            'opening_credit' => 0,
            'period_debit' => 0,
            'period_credit' => 0,
            'closing_debit' => 0,
            'closing_credit' => 0,
        ];

        foreach ($accounts as $account) {
            $opening = AccountingAmount::addSignedCents(
                AccountingAmount::toCents((string) $account['opening_debit']),
                -AccountingAmount::toCents((string) $account['opening_credit'])
            );
            $debit = AccountingAmount::toCents((string) $account['period_debit']);
            $credit = AccountingAmount::toCents((string) $account['period_credit']);
            $closing = AccountingAmount::addSignedCents($opening, $debit, -$credit);

            $row = [
                max(0, $opening),
                max(0, -$opening),
                $debit,
                $credit,
                max(0, $closing),
                max(0, -$closing),
            ];
            foreach (array_keys($totals) as $index => $key) {
                $totals[$key] = AccountingAmount::addCents($totals[$key], $row[$index]);
            }

            fputcsv($stream, [
                $this->safeText((string) $account['code']),
                $this->safeText((string) $account['name']),
                $this->safeText((string) $account['account_class']),
                AccountingAmount::formatFrenchCents($row[0]),
                AccountingAmount::formatFrenchCents($row[1]),
                AccountingAmount::formatFrenchCents($row[2]),
                AccountingAmount::formatFrenchCents($row[3]),
                AccountingAmount::formatFrenchCents($row[4]),
                AccountingAmount::formatFrenchCents($row[5]),
            ], ';', '"', '');
        }

        fputcsv($stream, [
            'TOTAL', '', '',
            AccountingAmount::formatFrenchCents($totals['opening_debit']),
            AccountingAmount::formatFrenchCents($totals['opening_credit']),
            AccountingAmount::formatFrenchCents($totals['period_debit']),
            AccountingAmount::formatFrenchCents($totals['period_credit']),
            AccountingAmount::formatFrenchCents($totals['closing_debit']),
            AccountingAmount::formatFrenchCents($totals['closing_credit']),
        ], ';', '"', '');

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        if ($csv === false) {
            throw new \\RuntimeException('Impossible de lire le fichier CSV généré.');
        }

        return $csv;
    }

    private function safeText(string $value): string
    {
        return preg_match('/^[\\s]*[=+@\\-]/u', $value) ? "'".$value : $value;
    }
}
