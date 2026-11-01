<?php

declare(strict_types=1);

use App\Entity\SalesInvoice;
use App\Entity\CompanyProfile;
use App\Entity\SalesOrder;
use App\Entity\SalesPayment;
use App\Entity\SalesQuote;
use App\Entity\SalesQuoteLine;
use App\Entity\ThirdParty;

require dirname(__DIR__).'/vendor/autoload.php';

function expectInvoice(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$customer = new ThirdParty('Client facture test', ThirdParty::CATEGORY_CUSTOMER);
$quote = new SalesQuote($customer);
$quote->addLine(new SalesQuoteLine('Prestation', '2', '50.00', '20.00', 1));
$quote->setStatus(SalesQuote::STATUS_SENT)->setStatus(SalesQuote::STATUS_ACCEPTED);
$order = new SalesOrder($quote);
$invoice = new SalesInvoice($order);

expectInvoice($invoice->getStatus() === SalesInvoice::STATUS_DRAFT, 'New invoices must remain drafts.');
expectInvoice($invoice->getNumber() === null, 'Draft invoices must not receive an official number.');
expectInvoice($invoice->getLines()->count() === 1, 'Invoice must copy order lines.');
expectInvoice($invoice->getTotalExcludingTax() === 100.0, 'Invoice subtotal must match order subtotal.');
expectInvoice($invoice->getTotalTax() === 20.0, 'Invoice VAT must be calculated per line.');
expectInvoice($invoice->getTotalIncludingTax() === 120.0, 'Invoice total must include VAT.');
expectInvoice($invoice->getCustomer() === $customer, 'Invoice must retain the order customer.');
expectInvoice($invoice->getPaidAmount() === 0.0, 'New invoices must have no payments.');
expectInvoice($invoice->getRemainingAmount() === 120.0, 'New invoices must have their full balance outstanding.');

$profile = new CompanyProfile();
$profile->update([
    'legalName' => 'Société de test', 'address' => '1 rue du Test',
    'postalCode' => '17000', 'city' => 'La Rochelle', 'country' => 'France',
    'siren' => '123456789', 'siret' => '12345678900012',
    'paymentTerms' => 'Paiement à 30 jours', 'latePenaltyRate' => 'Taux légal applicable', 'recoveryFee' => '40.00',
    'vatRegimeNote' => 'TVA selon régime applicable',
]);
expectInvoice($profile->getInvoiceReadinessErrors() === [], 'Complete seller profile must be ready for invoice issuance.');
$customerSnapshot = ['name' => 'Client facture test', 'address' => '2 rue Client', 'postal_code' => '17000', 'city' => 'La Rochelle'];
$invoice->issue('FAC-2026-000001', new DateTimeImmutable('2026-10-30 12:00:00'), new DateTimeImmutable('2026-11-29 12:00:00'), $profile->toInvoiceSnapshot(), $customerSnapshot);
expectInvoice($invoice->getStatus() === SalesInvoice::STATUS_ISSUED, 'Issuing a draft must change its status.');
expectInvoice($invoice->getNumber() === 'FAC-2026-000001', 'Issuance must assign the official invoice number.');
expectInvoice($invoice->getSellerSnapshot()['siren'] === '123456789', 'Seller identity must be snapshotted.');
expectInvoice($invoice->getDueDate()->format('Y-m-d') === '2026-11-29', 'Invoice due date must use the configured payment delay.');
try {
    $invoice->issue('FAC-2026-000002', new DateTimeImmutable('2026-10-30 12:01:00'), new DateTimeImmutable('2026-11-29 12:01:00'), $profile->toInvoiceSnapshot(), $customerSnapshot);
    throw new RuntimeException('An invoice was issued twice.');
} catch (InvalidArgumentException) {
    // An issued invoice is immutable and cannot receive a second number.
}
$firstPayment = new SalesPayment($invoice, '40.00', new DateTimeImmutable('2026-10-30'), 'bank_transfer', 'VIR-001');
$invoice->addPayment($firstPayment);
expectInvoice($invoice->getStatus() === SalesInvoice::STATUS_PARTIALLY_PAID, 'Partial payment must update invoice status.');
expectInvoice($invoice->getPaidAmount() === 40.0, 'Paid amount must aggregate payments.');
expectInvoice($invoice->getRemainingAmount() === 80.0, 'Remaining balance must decrease after payment.');

$secondPayment = new SalesPayment($invoice, '80.00', new DateTimeImmutable('2026-10-30'), 'card');
$invoice->addPayment($secondPayment);
expectInvoice($invoice->getStatus() === SalesInvoice::STATUS_PAID, 'Full payment must mark invoice as paid.');
expectInvoice($invoice->getRemainingAmount() === 0.0, 'Fully paid invoice must have no remaining balance.');

try {
    $invoice->addPayment(new SalesPayment($invoice, '1.00', new DateTimeImmutable('2026-10-30'), 'cash'));
    throw new RuntimeException('An overpayment was accepted.');
} catch (InvalidArgumentException) {
    // Fully settled invoices reject further payments.
}

$order->setStatus(SalesOrder::STATUS_CANCELLED);
try {
    new SalesInvoice($order);
    throw new RuntimeException('A cancelled order was invoiced.');
} catch (InvalidArgumentException) {
    // Cancelled orders cannot be invoiced.
}

echo "Sales invoice and payment smoke tests passed.\n";
