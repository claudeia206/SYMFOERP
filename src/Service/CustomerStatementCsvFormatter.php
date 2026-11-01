<?php
declare(strict_types=1);

namespace App\\Service;

final class CustomerStatementCsvFormatter
{
    /**
     * @param list<array<string, mixed>> $movements
     */
    public static function format(array $movements): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \\RuntimeException('Impossible de préparer le relevé CSV.');
        }

        fwrite($stream, "\\xEF\\xBB\\xBF");
        fputcsv($stream, ['Date', 'Type', 'Document', 'Description', 'Débit (€)', 'Crédit (€)', 'Solde (€)'], ';', '"', '');

        foreach ($movements as $movement) {
            $type = match ((string) ($movement['kind'] ?? '')) {
                'invoice' => 'Facture',
                'payment' => 'Règlement',
                'credit' => 'Avoir',
                default => 'Autre',
            };
            fputcsv($stream, [
                (string) ($movement['movement_date'] ?? ''),
                $type,
                self::safeText((string) ($movement['document'] ?? '')),
                self::safeText((string) ($movement['description'] ?? '')),
                (string) ($movement['debit'] ?? '0,00'),
                (string) ($movement['credit'] ?? '0,00'),
                (string) ($movement['balance'] ?? '0,00'),
            ], ';', '"', '');
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        if ($csv === false) {
            throw new \\RuntimeException('Impossible de lire le relevé CSV généré.');
        }

        return $csv;
    }

    private static function safeText(string $value): string
    {
        return preg_match('/^[\\x00-\\x20]*[=+\\-@]/u', $value) === 1 ? "'".$value : $value;
    }
}
