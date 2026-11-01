<?php
declare(strict_types=1);

use App\Controller\BankReconciliationController;
use Symfony\Component\Routing\Attribute\Route;

require dirname(__DIR__).'/vendor/autoload.php';

function expectBankUnmatch(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$reflection = new ReflectionClass(BankReconciliationController::class);
$method = $reflection->getMethod('unmatch');
$routes = $method->getAttributes(Route::class);
expectBankUnmatch(count($routes) === 1, 'Unmatch action must expose exactly one route.');
$route = $routes[0]->newInstance();
expectBankUnmatch($route->getPath() === '/{id}/unmatch', 'Unmatch route path changed unexpectedly.');
expectBankUnmatch($route->getMethods() === ['POST'], 'Unmatch route must only accept POST requests.');

$source = file_get_contents(dirname(__DIR__).'/src/Controller/BankReconciliationController.php');
expectBankUnmatch(is_string($source), 'Unable to inspect bank reconciliation controller.');
$start = strpos($source, 'public function unmatch(');
$end = strpos($source, 'private function toCents(', $start === false ? 0 : $start);
expectBankUnmatch($start !== false && $end !== false, 'Unmatch action source could not be isolated.');
$action = substr($source, $start, $end - $start);

foreach ([
    'isCsrfTokenValid' => 'Unmatch action must validate its CSRF token.',
    'FOR UPDATE' => 'Unmatch action must lock the bank transaction.',
    'matched_sales_payment_id' => 'Unmatch action must support customer payment matches.',
    'matched_supplier_payment_id' => 'Unmatch action must support supplier payment matches.',
    'SET {$column}=NULL' => 'Unmatch action must only clear the selected match.',
    'beginTransaction' => 'Unmatch action must run in a transaction.',
    'rollBack' => 'Unmatch action must roll back failed operations.',
] as $needle => $message) {
    expectBankUnmatch(str_contains($action, $needle), $message);
}
expectBankUnmatch(!str_contains($action, 'UPDATE app_sales_payment'), 'Unmatch action must never modify posted customer payments.');
expectBankUnmatch(!str_contains($action, 'UPDATE app_supplier_payment'), 'Unmatch action must never modify posted supplier payments.');

$controller = $reflection->newInstanceWithoutConstructor();
$toCents = $reflection->getMethod('toCents');
$toCents->setAccessible(true);
foreach ([
    ['125.00', 12500],
    ['0.01', 1],
    ['-45.67', -4567],
    ['0', 0],
] as [$input, $expected]) {
    expectBankUnmatch($toCents->invoke($controller, $input) === $expected, 'Bank amount conversion failed for '.$input.'.');
}
foreach (['', '01.00', '1.001', '+1.00', '1e3', '10000000000.00'] as $invalid) {
    $rejected = false;
    try {
        $toCents->invoke($controller, $invalid);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    expectBankUnmatch($rejected, 'Invalid bank amount was accepted: '.$invalid);
}

echo "Bank reconciliation unmatch regression smoke tests passed.\n";
