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
$template = file_get_contents(dirname(__DIR__).'/templates/accounting/fec.html.twig');
expectFiscalClosing(is_string($source), 'Unable to inspect fiscal closing validation.');
expectFiscalClosing(is_string($template), 'Unable to inspect fiscal closing screen.');

foreach ([
    'COUNT(l.id)<2',
    'COALESCE(SUM(l.debit),0)<>COALESCE(SUM(l.credit),0)',
    'l.debit<0 OR l.credit<0',
    '(l.debit>0 AND l.credit>0)',
    '(l.debit=0 AND l.credit=0)',
    'countInvalidPostedEntries($db, $from, $to)',
    'COUNT(l.id) AS line_count',
    "\$summary['line_count'] ?? 0",
] as $required) {
    expectFiscalClosing(str_contains($source, $required), 'Missing fiscal closing integrity check: '.$required);
}

foreach ([
    'Verrouiller les écritures de {{ year }}',
    'ne génère ni journal de clôture, ni résultat affecté, ni à-nouveaux',
] as $required) {
    expectFiscalClosing(str_contains($template, $required), 'Missing lock-only disclosure: '.$required);
}

echo "Fiscal closing persisted journal-line integrity checks passed.\n";
