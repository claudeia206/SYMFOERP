<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'app_supplier_invoice', uniqueConstraints: [
    new ORM\UniqueConstraint(name: 'uniq_supplier_invoice_order', columns: ['purchase_order_id']),
    new ORM\UniqueConstraint(name: 'uniq_supplier_invoice_reference', columns: ['supplier_id', 'supplier_invoice_number']),
])]
#[ORM\Index(name: 'idx_supplier_invoice_status_due', columns: ['status', 'due_date'])]
class SupplierInvoice
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_PARTIALLY_PAID = 'partially_paid';
    public const STATUS_PAID = 'paid';
    public const STATUS_OVERDUE = 'overdue';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: PurchaseOrder::class)]
    #[ORM\JoinColumn(name: 'purchase_order_id', nullable: false, onDelete: 'RESTRICT')]
    private PurchaseOrder $purchaseOrder;

    #[ORM\ManyToOne(targetEntity: ThirdParty::class)]
    #[ORM\JoinColumn(name: 'supplier_id', nullable: false, onDelete: 'RESTRICT')]
    private ThirdParty $supplier;

    #[ORM\Column(name: 'supplier_invoice_number', length: 120)]
    private string $supplierInvoiceNumber;

    #[ORM\Column(length: 24)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(name: 'invoice_date', type: 'date_immutable')]
    private DateTimeImmutable $invoiceDate;

    #[ORM\Column(name: 'due_date', type: 'date_immutable')]
    private DateTimeImmutable $dueDate;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'received_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $receivedAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    /** @var Collection<int, SupplierInvoiceLine> */
    #[ORM\OneToMany(mappedBy: 'invoice', targetEntity: SupplierInvoiceLine::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $lines;

    /** @var Collection<int, SupplierPayment> */
    #[ORM\OneToMany(mappedBy: 'invoice', targetEntity: SupplierPayment::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['paidAt' => 'DESC', 'id' => 'DESC'])]
    private Collection $payments;

    public function __construct(PurchaseOrder $order, string $supplierInvoiceNumber)
    {
        if (!in_array($order->getStatus(), [PurchaseOrder::STATUS_PARTIALLY_RECEIVED, PurchaseOrder::STATUS_RECEIVED], true)) {
            throw new InvalidArgumentException('Une facture fournisseur ne peut être créée qu’après une réception.');
        }
        $reference = trim($supplierInvoiceNumber);
        if ($reference === '' || mb_strlen($reference) > 120) {
            throw new InvalidArgumentException('La référence de facture fournisseur est obligatoire (120 caractères maximum).');
        }
        $this->purchaseOrder = $order;
        $this->supplier = $order->getSupplier();
        $this->supplierInvoiceNumber = $reference;
        $this->invoiceDate = new DateTimeImmutable('today');
        $this->dueDate = new DateTimeImmutable('+30 days');
        $this->createdAt = new DateTimeImmutable();
        $this->lines = new ArrayCollection();
        $this->payments = new ArrayCollection();

        foreach ($order->getLines() as $line) {
            if ((float) $line->getReceivedQuantity() <= 0) { continue; }
            $this->addLine(new SupplierInvoiceLine(
                $line->getProduct(),
                $line->getDescription(),
                $line->getReceivedQuantity(),
                $line->getUnitPrice(),
                $line->getVatRate(),
                $line->getPosition()
            ));
        }
        if ($this->lines->isEmpty()) {
            throw new InvalidArgumentException('Aucune quantité réceptionnée ne peut être facturée.');
        }
    }

    public function getId(): ?int { return $this->id; }
    public function getPurchaseOrder(): PurchaseOrder { return $this->purchaseOrder; }
    public function getSupplier(): ThirdParty { return $this->supplier; }
    public function getSupplierInvoiceNumber(): string { return $this->supplierInvoiceNumber; }
    public function getStatus(): string { return $this->status; }
    public function getInvoiceDate(): DateTimeImmutable { return $this->invoiceDate; }
    public function getDueDate(): DateTimeImmutable { return $this->dueDate; }
    public function getReceivedAt(): ?DateTimeImmutable { return $this->receivedAt; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = trim((string) $notes) !== '' ? mb_substr(trim((string) $notes), 0, 5000) : null; return $this; }
    public function addLine(SupplierInvoiceLine $line): self { if (!$this->lines->contains($line)) { $this->lines->add($line); $line->setInvoice($this); } return $this; }
    /** @return Collection<int, SupplierInvoiceLine> */
    public function getLines(): Collection { return $this->lines; }
    /** @return Collection<int, SupplierPayment> */
    public function getPayments(): Collection { return $this->payments; }

    public function receive(DateTimeImmutable $invoiceDate, DateTimeImmutable $dueDate): self
    {
        if ($this->status !== self::STATUS_DRAFT || $dueDate < $invoiceDate) {
            throw new InvalidArgumentException('La facture doit être en brouillon et son échéance ne peut précéder sa date.');
        }
        $this->invoiceDate = $invoiceDate;
        $this->dueDate = $dueDate;
        $this->receivedAt = new DateTimeImmutable();
        $this->status = self::STATUS_RECEIVED;
        return $this;
    }

    public function addPayment(SupplierPayment $payment): self
    {
        if ($payment->getInvoice() !== $this || !in_array($this->status, [self::STATUS_RECEIVED, self::STATUS_PARTIALLY_PAID], true)) {
            throw new InvalidArgumentException('Seule une facture fournisseur reçue peut être réglée.');
        }
        if ($this->getRemainingAmount() <= 0 || (float) $payment->getAmount() > $this->getRemainingAmount() + 0.001) {
            throw new InvalidArgumentException('Le règlement dépasse le solde restant ou la facture est déjà soldée.');
        }
        if (!$this->payments->contains($payment)) { $this->payments->add($payment); }
        $this->status = $this->getRemainingAmount() <= 0.001 ? self::STATUS_PAID : self::STATUS_PARTIALLY_PAID;
        return $this;
    }

    public function getTotalExcludingTax(): float { return array_reduce($this->lines->toArray(), static fn(float $s, SupplierInvoiceLine $l): float => $s + $l->getTotalExcludingTax(), 0.0); }
    public function getTotalTax(): float { return array_reduce($this->lines->toArray(), static fn(float $s, SupplierInvoiceLine $l): float => $s + $l->getTotalTax(), 0.0); }
    public function getTotalIncludingTax(): float { return round($this->getTotalExcludingTax() + $this->getTotalTax(), 2); }
    public function getPaidAmount(): float { return round(array_reduce($this->payments->toArray(), static fn(float $s, SupplierPayment $p): float => $s + (float) $p->getAmount(), 0.0), 2); }
    public function getRemainingAmount(): float { return max(0.0, round($this->getTotalIncludingTax() - $this->getPaidAmount(), 2)); }
    public function getEffectiveStatus(?DateTimeImmutable $today = null): string
    {
        if (in_array($this->status, [self::STATUS_RECEIVED, self::STATUS_PARTIALLY_PAID], true) && $this->getRemainingAmount() > 0 && $this->dueDate < ($today ?? new DateTimeImmutable('today'))) { return self::STATUS_OVERDUE; }
        return $this->status;
    }
}
