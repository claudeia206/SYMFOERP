<?php
declare(strict_types=1);

use App\\Controller\\BankReconciliationController;
use App\\Entity\\BankTransaction;
use Symfony\\Component\\Routing\\Attribute\\Route;

require dirname(__DIR__).'/vendor/autoload.php';

function expectBankMatch(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$reflection = new ReflectionClass(BankReconciliationController::class);
$method = $reflection->getMethod('match');
$routes = $method->getAttributes(Route::class);
expectBankMatch(count($routes) === 1, 'Match action must expose exactly one route.');
$route = $routes[0]->newInstance();
expectBankMatch($route->getPath() === '/{id}/match', 'Match route path changed unexpectedly.');
expectBankMatch($route->getMethods() === ['POST'], 'Match route must only accept POST requests.');

$source = file_get_contents(dirname(__DIR__).'/src/Controller/BankReconciliationController.php');
expectBankMatch(is_string($source), 'Unable to inspect bank reconciliation controller.');
$start = strpos($source, 'public function match(');
$end = strpos($source, 'public function unmatch(', $start === false ? 0 : $start);
expectBankMatch($start !== false && $end !== false, 'Match action source could not be isolated.');
$action = substr($source, $start, $end - $start);

foreach ([
    'isCsrfTokenValid' => 'Match action must validate its CSRF token.',
    'beginTransaction' => 'Match action must use a transaction.',
    'FOR UPDATE' => 'Match action must lock both bank transaction and payment.',
    "in_array($type,['sales','supplier'],true)" => 'Match action must restrict payment types.',
    "preg_match('/^[1-9]\\\\d{0,8}$/D',$rawPaymentId)" => 'Match action must validate the payment identifier.',
    'matched_sales_payment_id' => 'Match action must prevent duplicate customer matches.',
    'matched_supplier_payment_id' => 'Match action must prevent duplicate supplier matches.',
    "($type==='sales'&&$amountCents<=0)" => 'Customer matches must require a positive bank amount.',
    "($type==='supplier'&&$amountCents>=0)" => 'Supplier matches must require a negative bank amount.',
    'abs($amountCents)!==$expectedCents' => 'Match action must compare exact absolute amounts in cents.',
    'AND matched_sales_payment_id IS NULL AND matched_supplier_payment_id IS NULL' => 'Match update must be conditional against concurrent matches.',
    'rollBack' => 'Failed matches must roll back.',
] as $needle => $message) {
    expectBankMatch(str_contains($action, $needle), $message);
}
expectBankMatch(!str_contains($action, 'UPDATE app_sales_payment'), 'Matching must not alter customer payment records.');
expectBankMatch(!str_contains($action, 'UPDATE app_supplier_payment'), 'Matching must not alter supplier payment records.');

$entity = file_get_contents(dirname(__DIR__).'/src/Entity/BankTransaction.php');
expectBankMatch(is_string($entity), 'Unable to inspect bank transaction entity.');
expectBankMatch(str_contains($entity, "name: 'uniq_bank_transaction_sales_payment'"), 'Customer payment matches must be unique at database level.');
expectBankMatch(str_contains($entity, "name: 'uniq_bank_transaction_supplier_payment'"), 'Supplier payment matches must be unique at database level.');
expectBankMatch(str_contains($entity, 'function matchSalesPayment('), 'Bank transaction entity must validate customer matches.');
expectBankMatch(str_contains($entity, 'function matchSupplierPayment('), 'Bank transaction entity must validate supplier matches.');

$controller = $reflection->newInstanceWithoutConstructor();
$toCents = $reflection->getMethod('toCents');
$toCents->setAccessible(true);
foreach ([
    ['125.00', 12500],
    ['0.01', 1],
    ['-45.67', -4567],
    ['0', 0],
] as [$input, $expected]) {
    expectBankMatch($toCents->invoke($controller, $input) === $expected, 'Bank amount conversion failed for '.$input.'.');
}
foreach (['', '01.00', '1.001', '+1.00', '1e3', '10000000000.00'] as $invalid) {
    $rejected = false;
    try {
        $toCents->invoke($controller, $invalid);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    expectBankMatch($rejected, 'Invalid bank amount was accepted: '.$invalid);
}

echo "Bank reconciliation match regression smoke tests passed.\\n";
