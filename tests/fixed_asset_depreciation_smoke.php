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

try {
    $sequence->assertPeriodComplete(new DateTimeImmutable('2026-10-31'), 2026);
    throw new RuntimeException('A partial fiscal-year depreciation period must not be posted.');
} catch (InvalidArgumentException) {
}
$sequence->assertPeriodComplete(new DateTimeImmutable('2026-12-31'), 2026);



$scenarios = [
    ['2024-02-29', 1],
    ['2025-12-31', 3],
    ['2026-01-01', 7],
    ['2026-06-30', 40],
];
foreach ($scenarios as [$startDate, $years]) {
    $scenarioStart = new DateTimeImmutable($startDate);
    $scenarioEnd = $scenarioStart->modify(sprintf('+%d years', $years));
    $scenarioRows = $calculator->straightLine('12345.67', '123.45', $years, $scenarioStart, $scenarioEnd);
    $allocated = 0;
    $previousAccumulated = 0;
    $previousYear = 0;
    foreach ($scenarioRows as $scenarioRow) {
        $amount = App\Service\AccountingAmount::toCents($scenarioRow['amount']);
        $accumulated = App\Service\AccountingAmount::toCents($scenarioRow['accumulated']);
        if ($amount <= 0 || $accumulated < $previousAccumulated || (int) $scenarioRow['period'] <= $previousYear) {
            throw new RuntimeException('Depreciation rows must be positive, cumulative and ordered by calendar year.');
        }
        $allocated += $amount;
        if ($accumulated !== $allocated) {
            throw new RuntimeException('Displayed accumulated depreciation must equal the sum of plan periods.');
        }
        $previousAccumulated = $accumulated;
        $previousYear = (int) $scenarioRow['period'];
    }
    if ($allocated !== 1222222 || $scenarioRows[array_key_last($scenarioRows)]['net_book_value'] !== '123.45') {
        throw new RuntimeException(sprintf('Depreciation did not preserve residual value for %s / %d years.', $startDate, $years));
    }
}

try {
    $calculator->straightLine('100.00', '0.00', 5, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2025-12-31'));
    throw new RuntimeException('Depreciation before the in-service date must be rejected.');
} catch (InvalidArgumentException) {
}

echo "Fixed asset exact-cent depreciation and posting-order smoke tests passed.\n";
