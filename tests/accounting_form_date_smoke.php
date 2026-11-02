<?php

declare(strict_types=1);

use App\Controller\SalesInvoiceController;
use App\Controller\SupplierInvoiceController;

require dirname(__DIR__).'/vendor/autoload.php';

function expectFormDate(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

foreach ([SalesInvoiceController::class, SupplierInvoiceController::class] as $controllerClass) {
    $controller = (new ReflectionClass($controllerClass))->newInstanceWithoutConstructor();
    $parser = new ReflectionMethod($controllerClass, 'parseFormDate');
    $parser->setAccessible(true);

    foreach (['2026-01-01', '2024-02-29', '2000-12-31'] as $valid) {
        $parsed = $parser->invoke($controller, $valid, 'Date de test');
        expectFormDate($parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $valid, $controllerClass.' rejected valid date '.$valid);
    }

    foreach (['', ' ', '2026-2-01', '01-02-2026', '2026-02-29', '2026-04-31', '2026-13-01', 'tomorrow', '2026-01-01 10:00:00'] as $invalid) {
        $rejected = false;
        try {
            $parser->invoke($controller, $invalid, 'Date de test');
        } catch (InvalidArgumentException) {
            $rejected = true;
        }
        expectFormDate($rejected, $controllerClass.' accepted invalid date '.var_export($invalid, true));
    }
}

echo "Strict accounting form date validation smoke tests passed.\n";
