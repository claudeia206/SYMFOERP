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
}
