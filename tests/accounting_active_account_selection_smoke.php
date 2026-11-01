<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

function expectActiveAccountingAccount(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$controller = file_get_contents(dirname(__DIR__).'/src/Controller/GeneralLedgerController.php');
$template = file_get_contents(dirname(__DIR__).'/templates/general_ledger/index.html.twig');
expectActiveAccountingAccount(is_string($controller) && is_string($template), 'Unable to inspect general ledger account selection.');
expectActiveAccountingAccount(
    str_contains($controller, 'a.account_class,a.is_active'),
    'General ledger query must expose account activity state.'
);
expectActiveAccountingAccount(
    str_contains($template, '{% if account.is_active %}<option value="{{ account.id }}">'),
    'Manual journal form must not offer inactive accounts.'
);
expectActiveAccountingAccount(
    str_contains($template, '{% for row in 0..9 %}'),
    'Manual journal form must provide ten editable lines.'
);
expectActiveAccountingAccount(
    str_contains($controller, 'count($raw)>30'),
    'Manual journal endpoint must enforce its 30-line safety limit.'
);
expectActiveAccountingAccount(
    str_contains($controller, "WHERE id=? AND is_active=1 FOR UPDATE"),
    'Manual journal posting must independently reject inactive accounts.'
);

echo "Accounting account selection and manual journal capacity smoke tests passed.\n";
