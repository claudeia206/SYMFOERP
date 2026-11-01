<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

function expectVatDeclaration(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$source = file_get_contents(dirname(__DIR__).'/src/Controller/VatDeclarationController.php');
expectVatDeclaration(is_string($source), 'Unable to inspect VAT declaration calculation.');

foreach ([
    "\$month['net_label']=\$monthNet>0?'à payer':(\$monthNet<0?'crédit':'solde nul');",
    "'net_label'=>\$netCents>0?'TVA nette à payer':(\$netCents<0?'Crédit de TVA théorique':'Solde de TVA nul'),",
    "AccountingAmount::addSignedCents(\$collectedCents,-\$deductibleCents)",
    "e.status='posted'",
] as $required) {
    expectVatDeclaration(str_contains($source, $required), 'Missing VAT declaration behavior: '.$required);
}

echo "VAT declaration zero-balance checks passed.\\n";
