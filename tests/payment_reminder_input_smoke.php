<?php
declare(strict_types=1);

use App\Service\PaymentReminderInput;

require dirname(__DIR__).'/vendor/autoload.php';

function expectReminder(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$valid = PaymentReminderInput::validate('2', 'email', 'client@example.test', ' Appel confirmé ');
expectReminder($valid === [
    'level' => 2,
    'channel' => 'email',
    'recipient' => 'client@example.test',
    'note' => 'Appel confirmé',
], 'Payment reminder input must be normalized consistently.');

$phone = PaymentReminderInput::validate('1', 'phone', 'client@example.test', '');
expectReminder($phone['recipient'] === null && $phone['note'] === null, 'Non-email reminders must not store an email recipient.');
foreach ([
    ['0', 'email', 'client@example.test', ''],
    ['4', 'email', 'client@example.test', ''],
    ['1.5', 'email', 'client@example.test', ''],
    ['1', 'sms', 'client@example.test', ''],
    ['1', 'email', '', ''],
    ['1', 'email', 'invalid-address', ''],
    ['1', 'letter', null, str_repeat('x', 2001)],
] as [$level, $channel, $email, $note]) {
    try {
        PaymentReminderInput::validate($level, $channel, $email, $note);
        throw new RuntimeException('Invalid reminder input was accepted.');
    } catch (InvalidArgumentException) {
    }
}

echo "Payment reminder input validation passed.\n";
