<?php
declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use InvalidArgumentException;

final class DepreciationCalculator
{
    /**
     * @return list<array{period:string, amount:string, accumulated:string, net_book_value:string}>
     */
    public function straightLine(string|int $cost, string|int $residual, int $years, DateTimeImmutable $start, DateTimeImmutable $asOf): array
    {
        $costCents = AccountingAmount::toCents($cost);
        $residualCents = AccountingAmount::toCents($residual);
        if ($costCents <= 0 || $residualCents < 0 || $residualCents >= $costCents || $years < 1 || $years > 100 || $asOf < $start) {
            throw new InvalidArgumentException('Paramètres d’amortissement invalides.');
        }

        $baseCents = $costCents - $residualCents;
        $end = $start->modify(sprintf('+%d years', $years));
        $totalDays = (int) $start->diff($end)->days;
        if ($totalDays < 1) {
            throw new InvalidArgumentException('Durée d’amortissement invalide.');
        }

        $wholePerDay = intdiv($baseCents, $totalDays);
        $remainder = $baseCents % $totalDays;
        $cursor = $start;
        $allocatedCents = 0;
        $rows = [];

        while ($cursor < $end && $cursor <= $asOf) {
            $next = $cursor->modify('first day of january next year');
            if ($next <= $cursor) {
                $next = $cursor->modify('+1 year');
            }
            if ($next > $end) {
                $next = $end;
            }
            $periodEnd = $next < $asOf->modify('+1 day') ? $next : $asOf->modify('+1 day');
            $elapsedDays = (int) $start->diff($periodEnd)->days;
            $cumulative = ($wholePerDay * $elapsedDays)
                + intdiv(($remainder * $elapsedDays * 2) + $totalDays, 2 * $totalDays);
            $amountCents = max(0, min($baseCents - $allocatedCents, $cumulative - $allocatedCents));

            if ($amountCents > 0) {
                $allocatedCents += $amountCents;
                $rows[] = [
                    'period' => $cursor->format('Y'),
                    'amount' => AccountingAmount::formatCents($amountCents),
                    'accumulated' => AccountingAmount::formatCents($allocatedCents),
                    'net_book_value' => AccountingAmount::formatCents($costCents - $allocatedCents),
                ];
            }
            $cursor = $next;
        }

        return $rows;
    }
}
