<?php
declare(strict_types=1);

use App\Entity\SalesCreditNote;
use App\Entity\SalesCreditNoteLine;
use App\Entity\SalesInvoice;
use App\Entity\SalesOrder;
use App\Entity\SalesQuote;
use App\Entity\SalesQuoteLine;
use App\Entity\ThirdParty;

require dirname(__DIR__).'/vendor/autoload.php';

function expectCredit(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$customer = new ThirdParty('Client avoir', ThirdParty::CATEGORY_CUSTOMER);
$quote = new SalesQuote($customer);
$quote->addLine(new SalesQuoteLine('Prestation', '2', '50.00', '20.00', 1));
$quote->setStatus(SalesQuote::STATUS_SENT);
$quote->setStatus(SalesQuote::STATUS_ACCEPTED);
$order = new SalesOrder($quote);
$invoice = new SalesInvoice($order);
$invoice->issue('FAC-2026-000001', new DateTimeImmutable('2026-10-30'), new DateTimeImmutable('2026-11-29'), [
    'legal_name' => 'Société test',
], [
    'name' => 'Client avoir',
    'address' => '1 rue du Test',
    'postal_code' => '17000',
    'city' => 'La Rochelle',
]);
$line = $invoice->getLines()->first();
$line->recordCredit('0.500');
$note = new SalesCreditNote($invoice, 'Geste commercial partiel', ['legal_name' => 'Société test'], [
    'name' => 'Client avoir',
]);
$noteLine = new SalesCreditNoteLine($note, $line, '0.500');
$note->addLine($noteLine);
$invoice->addCreditNote($note);
expectCredit($note->getTotalExcludingTax() === 25.0, 'Credit note subtotal must use the invoiced unit price.');
expectCredit($note->getTotalTax() === 5.0, 'Credit note VAT must use the original VAT rate.');
expectCredit($note->getTotalIncludingTax() === 30.0, 'Credit note total must include VAT.');
expectCredit($line->getCreditedQuantity() === '0.500', 'Credited quantity must be tracked on the invoice line.');
expectCredit($line->getCreditEligibleQuantity() === '1.500', 'Credit eligibility must exclude previous credits.');
$precisionLine = new SalesInvoiceLine('Précision quantité', '1.000', '1.00', '20.00', 2);
$precisionLine->recordCredit('0.001');
$precisionLine->recordCredit('0.002');
expectCredit($precisionLine->getCreditedQuantity() === '0.003', 'Repeated milliunit credits must add exactly without float drift.');
expectCredit($precisionLine->getCreditEligibleQuantity() === '0.997', 'Remaining credit quantity must use exact milliunit subtraction.');
foreach (['1e-3', '0.0001', '1,000'] as $invalidQuantity) {
    try {
        $precisionLine->recordCredit($invalidQuantity);
        throw new RuntimeException('Invalid credit quantity format was accepted: '.$invalidQuantity);
    } catch (InvalidArgumentException) {
    }
}

expectCredit($invoice->getCreditedAmount() === 30.0, 'Invoice must aggregate issued credit notes.');
expectCredit($invoice->getRemainingAmount() === 90.0, 'Credit notes must reduce the invoice receivable.');
try {
    $line->recordCredit('1.501');
    throw new RuntimeException('A quantity exceeding the remaining invoice line was credited.');
} catch (InvalidArgumentException) {
}
try {
    new SalesCreditNote($invoice, '', [], []);
    throw new RuntimeException('A credit note without a reason was accepted.');
} catch (InvalidArgumentException) {
}
echo "Customer credit note totals, VAT, remaining quantities and receivable invariants passed.\n";
