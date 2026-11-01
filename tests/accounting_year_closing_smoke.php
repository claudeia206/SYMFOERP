<?php

declare(strict_types=1);

use App\Controller\FiscalClosingController;
use Symfony\Component\Routing\Attribute\Route;

require dirname(__DIR__).'/vendor/autoload.php';

function expectFiscalClosing(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$reflection = new ReflectionClass(FiscalClosingController::class);
$closeMethod = $reflection->getMethod('closeYear');
$routes = $closeMethod->getAttributes(Route::class);
expectFiscalClosing(count($routes) === 1, 'Fiscal closing must expose one route.');
$route = $routes[0]->newInstance();
expectFiscalClosing($route->getPath() === '/fec/close', 'Unexpected fiscal closing route.');
expectFiscalClosing($route->getMethods() === ['POST'], 'Fiscal closing must be POST-only.');

$source = file_get_contents(dirname(__DIR__).'/src/Controller/FiscalClosingController.php');
expectFiscalClosing(is_string($source), 'Unable to inspect fiscal closing controller.');
foreach ([
    'isCsrfTokenValid',
    'FOR UPDATE',
    'closing_entry_id',
    'opening_entry_id',
    'AccountingYearClosingCalculator::closingLines',
    'AccountingYearClosingCalculator::openingLines',
    'beginTransaction',
    'rollBack',
    'commit',
    'unclosedHistoricalEntries',
    'nextYearEntries',
    'historicalProfitAndLossBalances',
] as $required) {
    expectFiscalClosing(str_contains($source, $required), 'Missing fiscal closing control: '.$required);
}

$migration = file_get_contents(dirname(__DIR__).'/migrations/Version20261101080000.php');
expectFiscalClosing(is_string($migration), 'Unable to inspect fiscal closing migration.');
foreach (['120000', '129000', 'closing_entry_id', 'opening_entry_id', 'ON DELETE RESTRICT'] as $required) {
    expectFiscalClosing(str_contains($migration, $required), 'Missing fiscal closing schema element: '.$required);
}

echo "Fiscal year close, result transfer and opening balance safeguards passed.\n";
