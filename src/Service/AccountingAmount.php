<?php
declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;

final class AccountingAmount
{
    public static function toCents(mixed $amount): int
    {
        if (!is_string($amount) && !is_int($amount)) {
            throw new InvalidArgumentException('Montant comptable non décimal.');
        }

        $value = (string) $amount;
        if (!preg_match('/^-?(?:0|[1-9]\d{0,12})(?:\.\d{1,2})?$/D', $value)) {
            throw new InvalidArgumentException('Montant comptable invalide : 13 chiffres et deux décimales maximum.');
        }

        $negative = str_starts_with($value, '-');
        $unsigned = $negative ? substr($value, 1) : $value;
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $cents = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');

        return $negative ? -$cents : $cents;
    }

    public static function formatCents(int $cents): string
    {
        $negative = $cents < 0;
        $absolute = abs($cents);

        return ($negative ? '-' : '').intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}
