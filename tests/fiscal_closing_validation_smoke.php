<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

function expectFiscalClosing(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$source = file_get_contents(dirname(__DIR__).'/src/Controller/FiscalClosingController.php');
expectFiscalClosing(is_string($source), 'Unable to inspect fiscal closing validation.');

foreach ([
    'COUNT(l.id)<2',
    'COALESCE(SUM(l.debit),0)<>COALESCE(SUM(l.credit),0)',
    'l.debit<0 OR l.credit<0',
    '(l.debit>0 AND l.credit>0)',
    '(l.debit=0 AND l.credit=0)',
    'countInvalidPostedEntries($db, $from, $to)',
] as $required) {
    expectFiscalClosing(str_contains($source, $required), 'Missing fiscal closing integrity check: '.$required);
}

echo "Fiscal closing persisted journal-line integrity checks passed.\n";
