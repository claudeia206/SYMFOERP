<?php
declare(strict_types=1);

use App\Service\DepreciationCalculator;
use App\Service\FixedAssetDepreciationSequence;

require dirname(__DIR__).'/vendor/autoload.php';

$calculator = new DepreciationCalculator();
$start = new DateTimeImmutable('2024-07-01');
$end = new DateTimeImmutable('2029-07-01');
$rows = $calculator->straightLine('10000.01', '0.01', 5, $start, $end);
if (count($rows) !== 6) {
    throw new RuntimeException('Expected calendar-year prorated depreciation periods.');
}
$totalCents = 0;
foreach ($rows as $row) {
    $totalCents += App\Service\AccountingAmount::toCents($row['amount']);
    if (!is_string($row['amount']) || !is_string($row['accumulated']) || !is_string($row['net_book_value'])) {
        throw new RuntimeException('Depreciation amounts must remain exact decimal strings.');
    }
}
if ($totalCents !== 1000000 || $rows[array_key_last($rows)]['net_book_value'] !== '0.01') {
    throw new RuntimeException('Depreciation must allocate the exact depreciable base and preserve residual value.');
}

$partial = $calculator->straightLine('0.03', '0.00', 3, new DateTimeImmutable('2025-01-01'), new DateTimeImmutable('2025-12-31'));
if (array_sum(array_map(static fn(array $row): int => App\Service\AccountingAmount::toCents($row['amount']), $partial)) !== 1) {
    throw new RuntimeException('Partial-period depreciation must round cumulatively to exact cents.');
}

$sequence = new FixedAssetDepreciationSequence();
try {
    $sequence->assertPriorPeriodsPosted($rows, 2026, [2024]);
    throw new RuntimeException('Out-of-order depreciation posting must be rejected.');
} catch (InvalidArgumentException $exception) {
    if (!str_contains($exception->getMessage(), '2025')) {
        throw $exception;
    }
}
$sequence->assertPriorPeriodsPosted($rows, 2026, [2024, 2025]);

echo "Fixed asset exact-cent depreciation and posting-order smoke tests passed.\n";
