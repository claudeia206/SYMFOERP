<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../src/Entity/SalesPayment.php');
if ($source === false) {
    fwrite(STDERR, "Unable to read SalesPayment entity.\n");
    exit(1);
}

$checks = [
    'positive bounded amount validation' => "if (\$amountInCents <= 0 || \$amountInCents > 999999999999)",
    'supported payment method validation' => "if (!in_array(\$method, self::METHODS, true))",
    'reference length validation' => "mb_strlen(\$reference) > 120",
    'cent-safe amount persistence' => "AccountingAmount::formatCents(\$amountInCents)",
];

foreach ($checks as $label => $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "Missing {$label}.\n");
        exit(1);
    }
}

if (substr_count($source, "if (!in_array(\$method, self::METHODS, true))") !== 1) {
    fwrite(STDERR, "Payment method validation must be defined exactly once.\n");
    exit(1);
}

echo "Sales payment validation smoke test passed.\n";
