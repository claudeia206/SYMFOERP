<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'app_supplier_payment')]
#[ORM\Index(name: 'idx_supplier_payment_invoice_date', columns: ['invoice_id', 'paid_at'])]
class SupplierPayment
{
    public const METHODS = ['bank_transfer', 'card', 'cash', 'check', 'other'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SupplierInvoice::class, inversedBy: 'payments')]
    #[ORM\JoinColumn(name: 'invoice_id', nullable: false, onDelete: 'RESTRICT')]
    private SupplierInvoice $invoice;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $amount;

    #[ORM\Column(name: 'paid_at', type: 'date_immutable')]
    private DateTimeImmutable $paidAt;

    #[ORM\Column(length: 20)]
    private string $method;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(SupplierInvoice $invoice, string $amount, DateTimeImmutable $paidAt, string $method, ?string $reference = null, ?string $note = null)
    {
        if (!is_numeric($amount) || (float) $amount <= 0 || (float) $amount > 9999999999.99 || !in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException('Montant ou moyen de règlement fournisseur invalide.');
        }
        $reference = $reference !== null ? trim($reference) : null;
        if ($reference !== null && mb_strlen($reference) > 120) { throw new InvalidArgumentException('Référence de règlement trop longue.'); }
        $this->invoice = $invoice;
        $this->amount = number_format((float) $amount, 2, '.', '');
        $this->paidAt = $paidAt;
        $this->method = $method;
        $this->reference = $reference !== '' ? $reference : null;
        $this->note = $note !== null ? trim($note) : null;
        $this->createdAt = new DateTimeImmutable();
    }
    public function getId(): ?int { return $this->id; }
    public function getInvoice(): SupplierInvoice { return $this->invoice; }
    public function getAmount(): string { return $this->amount; }
    public function getPaidAt(): DateTimeImmutable { return $this->paidAt; }
    public function getMethod(): string { return $this->method; }
    public function getReference(): ?string { return $this->reference; }
    public function getNote(): ?string { return $this->note; }
}
