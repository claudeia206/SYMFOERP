<?php
declare(strict_types=1);

namespace App\Service;

use RuntimeException;

final class FecExportFormatter
{
    public const COLUMNS = [
        'JournalCode', 'JournalLib', 'EcritureNum', 'EcritureDate',
        'CompteNum', 'CompteLib', 'CompAuxNum', 'CompAuxLib',
        'PieceRef', 'PieceDate', 'EcritureLib', 'Debit', 'Credit',
        'EcritureLet', 'DateLet', 'ValidDate', 'Montantdevise', 'Idevise',
    ];

    public function formatAmount(mixed $amount): string
    {
        if (!is_string($amount) && !is_int($amount)) {
            throw new RuntimeException('Montant FEC non décimal.');
        }

        $value = (string) $amount;
        if (!preg_match('/^\d{1,13}(?:\.\d{1,2})?$/D', $value)) {
            throw new RuntimeException('Montant FEC invalide ou précision supérieure à deux décimales.');
        }

        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $integer = ltrim($integer, '0');
        if ($integer === '') {
            $integer = '0';
        }

        return $integer.'.'.str_pad($fraction, 2, '0');
    }

    public function sanitizeField(mixed $value): string
    {
        $value = (string) ($value ?? '');
        $value = str_replace(["\r", "\n", '|'], [' ', ' ', '/'], $value);
        $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $value);

        if ($sanitized === null) {
            throw new RuntimeException('Texte FEC encodé de manière invalide.');
        }

        return trim($sanitized);
    }

    /** @param list<mixed> $values */
    public function formatRow(array $values): string
    {
        if (count($values) !== count(self::COLUMNS)) {
            throw new RuntimeException('Une ligne FEC doit contenir exactement 18 colonnes.');
        }

        return implode('|', array_map(fn (mixed $value): string => $this->sanitizeField($value), $values));
    }

    /** @param list<array<int, mixed>> $rows */
    public function formatDocument(array $rows): string
    {
        $lines = [implode('|', self::COLUMNS)];
        foreach ($rows as $row) {
            $lines[] = $this->formatRow($row);
        }

        return implode("\r\n", $lines)."\r\n";
    }
}
