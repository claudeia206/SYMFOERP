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
    'ne peut être clôturé qu’après sa date de fin',
] as $required) {
    expectFiscalClosing(str_contains($source, $required), 'Missing fiscal closing control: '.$required);
}

$fecTemplate = file_get_contents(dirname(__DIR__).'/templates/accounting/fec.html.twig');
expectFiscalClosing(is_string($fecTemplate) && str_contains($fecTemplate, '{{ summary.lines }}'), 'FEC template must render the normalized line count.');
expectFiscalClosing(str_contains($source, "'lines' => (int) ($summary['line_count'] ?? 0)"), 'FEC summary must map the SQL line_count alias.');
expectFiscalClosing(str_contains($source, 'COUNT(l.id) AS line_count'), 'FEC query must expose its line-count alias.');

$migration = file_get_contents(dirname(__DIR__).'/migrations/Version20261101080000.php');
expectFiscalClosing(is_string($migration), 'Unable to inspect fiscal closing migration.');
foreach (['120000', '129000', 'closing_entry_id', 'opening_entry_id', 'ON DELETE RESTRICT'] as $required) {
    expectFiscalClosing(str_contains($migration, $required), 'Missing fiscal closing schema element: '.$required);
}

$ledgerSource = file_get_contents(dirname(__DIR__).'/src/Controller/GeneralLedgerController.php');
expectFiscalClosing(is_string($ledgerSource) && str_contains($ledgerSource, 'opening_entry_id') && str_contains($ledgerSource, 'openingFrom'), 'General ledger must use fiscal-year opening balances.');

$statementsSource = file_get_contents(dirname(__DIR__).'/src/Controller/FinancialStatementsController.php');
expectFiscalClosing(is_string($statementsSource) && str_contains($statementsSource, 'closing_entry_id') && str_contains($statementsSource, 'opening_entry_id'), 'Financial statements must account for annual closing and opening entries.');

$vatSource = file_get_contents(dirname(__DIR__).'/src/Controller/VatDeclarationController.php');
expectFiscalClosing(is_string($vatSource) && str_contains($vatSource, 'fy.opening_entry_id = e.id'), 'VAT movement reports must exclude opening balance entries.');

echo "Fiscal year close, result transfer and opening balance safeguards passed.\n";
