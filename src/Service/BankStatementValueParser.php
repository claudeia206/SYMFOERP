<?php
declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use InvalidArgumentException;

final class BankStatementValueParser
{
    public function parseDate(string $value): DateTimeImmutable
    {
        foreach (['!Y-m-d' => 'Y-m-d', '!d/m/Y' => 'd/m/Y'] as $inputFormat => $roundTripFormat) {
            $date = DateTimeImmutable::createFromFormat($inputFormat, $value);
            $errors = DateTimeImmutable::getLastErrors();

            if (
                $date !== false
                && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && $date->format($roundTripFormat) === $value
            ) {
                return $date;
            }
        }

        throw new InvalidArgumentException('Date bancaire invalide.');
    }

    public function parseAmountToCents(string $value): int
    {
        $value = str_replace([" ", "\u{00A0}", "\u{202F}", '€'], '', trim($value));
        if (str_contains($value, ',') && str_contains($value, '.')) {
            throw new InvalidArgumentException('Montant bancaire ambigu.');
        }

        $value = str_replace(',', '.', $value);
        if (!preg_match('/^-?(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/D', $value)) {
            throw new InvalidArgumentException('Montant bancaire invalide.');
        }

        $negative = str_starts_with($value, '-');
        $unsigned = $negative ? substr($value, 1) : $value;
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $cents = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');

        return $negative ? -$cents : $cents;
    }

    public function formatCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return $sign.intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}
