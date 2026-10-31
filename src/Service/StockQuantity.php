<?php

declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;

final class StockQuantity
{
    public static function toMilliunits(mixed $value, bool $allowNegative = false): int
    {
        if (!is_string($value) && !is_int($value)) {
            throw new InvalidArgumentException('La quantité doit être un nombre décimal valide.');
        }

        $value = trim((string) $value);
        $pattern = $allowNegative ? '/^-?\d{1,9}(?:\.\d{1,3})?$/' : '/^\d{1,9}(?:\.\d{1,3})?$/';
        if (!preg_match($pattern, $value)) {
            throw new InvalidArgumentException('La quantité doit comporter au maximum trois décimales.');
        }

        $negative = str_starts_with($value, '-');
        $unsigned = $negative ? substr($value, 1) : $value;
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $milliunits = ((int) $whole * 1000) + (int) str_pad($fraction, 3, '0');
        return $negative ? -$milliunits : $milliunits;
    }

    public static function format(int $milliunits): string
    {
        $sign = $milliunits < 0 ? '-' : '';
        $absolute = abs($milliunits);
        return $sign.intdiv($absolute, 1000).'.'.str_pad((string) ($absolute % 1000), 3, '0', STR_PAD_LEFT);
    }
}
