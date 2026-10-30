<?php
declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use InvalidArgumentException;

final class DepreciationCalculator
{
    /** @return list<array{period:string, amount:float, accumulated:float, net_book_value:float}> */
    public function straightLine(float $cost, float $residual, int $years, DateTimeImmutable $start, DateTimeImmutable $asOf): array
    {
        if ($cost <= 0 || $residual < 0 || $residual >= $cost || $years < 1 || $years > 100 || $asOf < $start) {
            throw new InvalidArgumentException('Paramètres d’amortissement invalides.');
        }
        $base = round($cost - $residual, 2);
        $end = $start->modify(sprintf('+%d years', $years));
        $totalDays = (int) $start->diff($end)->days;
        if ($totalDays < 1) {
            throw new InvalidArgumentException('Durée d’amortissement invalide.');
        }
        // Use the exact calendar duration so the final period reaches the residual value.
        $daily = $base / $totalDays;
        $cursor = $start;
        $accumulated = 0.0;
        $rows = [];
        while ($cursor < $end && $cursor <= $asOf) {
            $next = $cursor->modify('first day of january next year');
            if ($next <= $cursor) $next = $cursor->modify('+1 year');
            if ($next > $end) $next = $end;
            $periodEnd = $next < $asOf->modify('+1 day') ? $next : $asOf->modify('+1 day');
            $days = (int) $cursor->diff($periodEnd)->days;
            $remaining = round($base - $accumulated, 2);
            // Absorb intermediate cent rounding in the final period so the
            // schedule always reaches the exact depreciable base at term end.
            $amount = $next >= $end
                ? $remaining
                : min($remaining, round($daily * $days, 2));
            if ($amount > 0) {
                $accumulated = round($accumulated + $amount, 2);
                $rows[] = ['period'=>$cursor->format('Y'), 'amount'=>$amount, 'accumulated'=>$accumulated, 'net_book_value'=>round($cost-$accumulated,2)];
            }
            $cursor = $next;
        }
        return $rows;
    }
}