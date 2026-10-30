<?php

declare(strict_types=1);

use App\\Service\\DepreciationCalculator;

require dirname(__DIR__).'/vendor/autoload.php';

function expectDepreciation(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$calculator = new DepreciationCalculator();
$start = new DateTimeImmutable('2024-07-01');
$partial = $calculator->straightLine(1000.00, 100.00, 3, $start, new DateTimeImmutable('2025-12-31'));

expectDepreciation(count($partial) === 2, 'A partial schedule must stop at the requested date.');
expectDepreciation($partial[0]['period'] === '2024', 'The first partial year must retain its service year.');
expectDepreciation($partial[1]['period'] === '2025', 'The second period must use its calendar year.');
expectDepreciation($partial[0]['amount'] < $partial[1]['amount'], 'The first partial year must be prorated.');
expectDepreciation($partial[1]['net_book_value'] >= 100.00, 'The schedule must not depreciate below residual value.');

$latePartial = $calculator->straightLine(1000.00, 100.00, 3, $start, new DateTimeImmutable('2027-01-01'));
$lateAccumulated = array_sum(array_column($latePartial, 'amount'));
expectDepreciation($lateAccumulated < 900.00, 'A cutoff before the useful-life end must not trigger the final catch-up amount.');
expectDepreciation($latePartial[array_key_last($latePartial)]['net_book_value'] > 100.00, 'A partial final year must remain above residual value.');

$complete = $calculator->straightLine(1000.00, 100.00, 3, $start, new DateTimeImmutable('2030-12-31'));
$sum = array_sum(array_column($complete, 'amount'));
$last = $complete[array_key_last($complete)];

expectDepreciation(abs($sum - 900.00) < 0.001, 'The complete schedule must depreciate the exact base despite cent rounding.');
expectDepreciation($last['period'] === '2027', 'The schedule must stop in the final service year.');
expectDepreciation(abs($last['net_book_value'] - 100.00) < 0.001, 'The final net book value must equal the residual value.');
expectDepreciation(count($complete) === 4, 'The schedule must contain the first and final prorated calendar years.');

echo "Fixed asset straight-line depreciation schedule smoke tests passed.\\n";
