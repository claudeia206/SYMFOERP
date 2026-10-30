<?php

declare(strict_types=1);

use App\Service\AccountingPeriodGuard;
use Doctrine\DBAL\Connection;

require dirname(__DIR__).'/vendor/autoload.php';

function expectPeriodGuard(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$guard = new AccountingPeriodGuard();
$db = (new ReflectionClass(Connection::class))->newInstanceWithoutConstructor();

// These cases must be rejected before the database is queried.
foreach ([
    '',
    ' ',
    '2026-2-01',
    '01-02-2026',
    '2026-02-29',
    '2026-04-31',
    '2026-13-01',
    'not-a-date',
    '1999-12-31',
    '2101-01-01',
] as $invalidDate) {
    $rejected = false;
    try {
        $guard->assertOpenDate($db, $invalidDate);
    } catch (RuntimeException) {
        $rejected = true;
    }

    expectPeriodGuard($rejected, 'Invalid or out-of-range fiscal date was accepted: '.$invalidDate);
}

echo "Accounting period guard date boundary smoke tests passed.\n";
