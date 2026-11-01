<?php

declare(strict_types=1);

use App\Controller\AccountingEntryReversalController;
use App\Service\AccountingEntryReversal;
use Symfony\Component\Routing\Attribute\Route;

require dirname(__DIR__).'/vendor/autoload.php';

function expectAccountingReversal(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$reversed = AccountingEntryReversal::reverseLines([
    ['account_id' => 411, 'label' => 'Client', 'debit' => '120.00', 'credit' => '0.00'],
    ['account_id' => 707, 'label' => 'Vente', 'debit' => '0.00', 'credit' => '100.00'],
    ['account_id' => 445, 'label' => 'TVA collectée', 'debit' => '0.00', 'credit' => '20.00'],
]);
expectAccountingReversal(count($reversed) === 3, 'All source lines must be retained.');
expectAccountingReversal($reversed[0]['debit'] === '0.00' && $reversed[0]['credit'] === '120.00', 'Customer debit must become a credit.');
expectAccountingReversal($reversed[1]['debit'] === '100.00' && $reversed[1]['credit'] === '0.00', 'Revenue credit must become a debit.');
expectAccountingReversal($reversed[2]['debit'] === '20.00' && $reversed[2]['credit'] === '0.00', 'VAT credit must become a debit.');

foreach ([
    [
        ['account_id' => 1, 'label' => 'A', 'debit' => '10.00', 'credit' => '0.00'],
        ['account_id' => 2, 'label' => 'B', 'debit' => '0.00', 'credit' => '9.99'],
    ],
    [
        ['account_id' => 1, 'label' => 'A', 'debit' => '0.00', 'credit' => '0.00'],
        ['account_id' => 2, 'label' => 'B', 'debit' => '0.00', 'credit' => '0.00'],
    ],
] as $invalidLines) {
    try {
        AccountingEntryReversal::reverseLines($invalidLines);
        throw new RuntimeException('Invalid journal lines were accepted for reversal.');
    } catch (InvalidArgumentException) {
    }
}

$reflection = new ReflectionClass(AccountingEntryReversalController::class);
$method = $reflection->getMethod('reverse');
$routes = $method->getAttributes(Route::class);
expectAccountingReversal(count($routes) === 1, 'Reversal action must expose one route.');
$route = $routes[0]->newInstance();
expectAccountingReversal($route->getPath() === '/{id}/reverse', 'Unexpected reversal route.');
expectAccountingReversal($route->getMethods() === ['POST'], 'Reversal route must be POST-only.');

$source = file_get_contents(dirname(__DIR__).'/src/Controller/AccountingEntryReversalController.php');
expectAccountingReversal(is_string($source), 'Unable to inspect reversal controller.');
foreach ([
    'isCsrfTokenValid',
    'FOR UPDATE',
    'reversal_of_entry_id',
    'ne peut pas être contrepassée à son tour',
    'assertOpenDate',
    'beginTransaction',
    'rollBack',
    'commit',
] as $required) {
    expectAccountingReversal(str_contains($source, $required), 'Missing reversal safety control: '.$required);
}

echo "Accounting entry reversal invariants passed.\n";
