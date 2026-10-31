<?php
declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;

final class PaymentReminderInput
{
    /**
     * @return array{level:int,channel:string,recipient:?string,note:?string}
     */
    public static function validate(mixed $level, mixed $channel, mixed $email, mixed $note): array
    {
        $validatedLevel = filter_var($level, FILTER_VALIDATE_INT);
        if (!in_array($validatedLevel, [1, 2, 3], true)) {
            throw new InvalidArgumentException('Niveau de relance invalide.');
        }

        if (!is_string($channel) || !in_array($channel, ['email', 'phone', 'letter'], true)) {
            throw new InvalidArgumentException('Canal de relance invalide.');
        }

        if ($email !== null && !is_string($email)) {
            throw new InvalidArgumentException('Adresse e-mail invalide.');
        }
        $email = is_string($email) ? trim($email) : '';
        if ($channel === 'email' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Aucune adresse e-mail valide n’est renseignée pour ce client.');
        }

        if ($note !== null && !is_string($note)) {
            throw new InvalidArgumentException('Commentaire de relance invalide.');
        }
        $note = trim((string) $note);
        if (mb_strlen($note) > 2000) {
            throw new InvalidArgumentException('Le commentaire ne peut pas dépasser 2 000 caractères.');
        }

        return [
            'level' => $validatedLevel,
            'channel' => $channel,
            'recipient' => $channel === 'email' ? $email : null,
            'note' => $note !== '' ? $note : null,
        ];
    }
}
