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

    public static function lineNetCents(string $quantity, string $unitPrice): int
    {
        $milliunits = StockQuantity::toMilliunits($quantity);
        $unitPriceCents = self::toCents($unitPrice);
        if ($milliunits < 0 || $unitPriceCents < 0) {
            throw new InvalidArgumentException('Les quantités et prix de ligne doivent être positifs ou nuls.');
        }

        // Journal entry amounts use NUMERIC(14, 2): at most 12 whole digits.
        $maximumNumerator = 99999999999999 * 1000 + 499;
        if ($unitPriceCents !== 0 && $milliunits > intdiv($maximumNumerator, $unitPriceCents)) {
            throw new InvalidArgumentException('Le total de ligne dépasse la précision comptable prise en charge.');
        }

        return intdiv($milliunits * $unitPriceCents + 500, 1000);
    }

    public static function taxCents(int $netCents, string $vatRate): int
    {
        $vatRateCents = self::toCents($vatRate);
        if ($netCents < 0 || $netCents > 99999999999999 || $vatRateCents < 0 || $vatRateCents > 10000) {
            throw new InvalidArgumentException('Base ou taux de TVA hors limites.');
        }

        $whole = intdiv($netCents, 10000) * $vatRateCents;
        $remainder = ($netCents % 10000) * $vatRateCents;

        return $whole + intdiv($remainder + 5000, 10000);
    }

    public static function addCents(int ...$amounts): int
    {
        $sum = 0;
        foreach ($amounts as $amount) {
            if ($amount < 0 || $amount > 99999999999999 - $sum) {
                throw new InvalidArgumentException('Le total dépasse la précision NUMERIC(14, 2) du journal comptable.');
            }
            $sum += $amount;
        }

        return $sum;
    }

    public static function addSignedCents(int ...$amounts): int
    {
        $sum = 0;
        foreach ($amounts as $amount) {
            if (($amount > 0 && $sum > PHP_INT_MAX - $amount)
                || ($amount < 0 && $sum < PHP_INT_MIN - $amount)) {
                throw new InvalidArgumentException('Le cumul des montants dépasse la capacité entière prise en charge.');
            }
            $sum += $amount;
            if ($sum === PHP_INT_MIN) {
                throw new InvalidArgumentException('Le cumul signé minimal ne peut pas être formaté en centimes.');
            }
        }

        return $sum;
    }

    public static function formatFrenchCents(int $cents): string
    {
        $formatted = self::formatCents($cents);
        $negative = str_starts_with($formatted, '-');
        $unsigned = $negative ? substr($formatted, 1) : $formatted;
        [$integer, $fraction] = explode('.', $unsigned, 2);
        $grouped = preg_replace('/\\B(?=(\\d{3})+(?!\\d))/', ' ', $integer);

        return ($negative ? '-' : '').$grouped.','.$fraction;
    }

    public static function formatCents(int $cents): string
    {
        $negative = $cents < 0;
        $absolute = abs($cents);

        return ($negative ? '-' : '').intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}
