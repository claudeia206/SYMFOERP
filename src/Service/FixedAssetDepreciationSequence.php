<?php
declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;

final class FixedAssetDepreciationSequence
{
    /**
     * @param list<array{period:string, amount:string}> $periods
     * @param list<int|string> $postedYears
     */
    public function assertPriorPeriodsPosted(array $periods, int $targetYear, array $postedYears): void
    {
        $posted = [];
        foreach ($postedYears as $year) {
            $posted[(int) $year] = true;
        }

        foreach ($periods as $period) {
            $year = (int) $period['period'];
            if ($year >= $targetYear) {
                continue;
            }

            if (!isset($posted[$year])) {
                throw new InvalidArgumentException(sprintf(
                    'Comptabilisez d’abord la dotation %d avant celle de %d.',
                    $year,
                    $targetYear
                ));
            }
        }
    }
    public function assertPeriodComplete(\DateTimeImmutable $asOf, int $targetYear): void
    {
        $periodEnd = new \DateTimeImmutable($targetYear.'-12-31');
        if ($asOf < $periodEnd) {
            throw new InvalidArgumentException(sprintf(
                'La simulation doit aller jusqu’au 31/12/%d avant de comptabiliser la dotation.',
                $targetYear
            ));
        }
    }

}
