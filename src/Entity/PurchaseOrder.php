<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PurchaseOrderRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity(repositoryClass: PurchaseOrderRepository::class)]
#[ORM\Table(name: 'app_purchase_order', uniqueConstraints: [new ORM\UniqueConstraint(name: 'uniq_purchase_order_number', columns: ['number'])])]
#[ORM\Index(name: 'idx_purchase_order_supplier_status', columns: ['supplier_id', 'status'])]
class PurchaseOrder
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_PARTIALLY_RECEIVED = 'partially_received';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $number = null;

    #[ORM\ManyToOne(targetEntity: ThirdParty::class)]
    #[ORM\JoinColumn(name: 'supplier_id', nullable: false, onDelete: 'RESTRICT')]
    private ThirdParty $supplier;

    #[ORM\Column(length: 24)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(name: 'order_date', type: 'date_immutable')]
    private DateTimeImmutable $orderDate;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    /** @var Collection<int, PurchaseOrderLine> */
    #[ORM\OneToMany(mappedBy: 'purchaseOrder', targetEntity: PurchaseOrderLine::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $lines;

    public function __construct(ThirdParty $supplier)
    {
        if ($supplier->getCategory() !== ThirdParty::CATEGORY_SUPPLIER) {
            throw new InvalidArgumentException('Une commande achat doit être liée à un fournisseur.');
        }
        $this->supplier = $supplier;
        $this->orderDate = new DateTimeImmutable('today');
        $this->createdAt = new DateTimeImmutable();
        $this->lines = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getNumber(): ?string { return $this->number; }
    public function assignNumber(): void
    {
        if ($this->id === null) { throw new InvalidArgumentException('La commande doit être enregistrée avant numérotation.'); }
        $this->number = sprintf('ACH-%s-%06d', $this->orderDate->format('Y'), $this->id);
    }
    public function getSupplier(): ThirdParty { return $this->supplier; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self
    {
        if (!in_array($status, [self::STATUS_DRAFT, self::STATUS_SENT, self::STATUS_PARTIALLY_RECEIVED, self::STATUS_RECEIVED, self::STATUS_CANCELLED], true)) {
            throw new InvalidArgumentException('Statut de commande fournisseur invalide.');
        }
        $this->status = $status;
        return $this;
    }
    public function getOrderDate(): DateTimeImmutable { return $this->orderDate; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = trim((string) $notes) !== '' ? mb_substr(trim((string) $notes), 0, 5000) : null; return $this; }
    public function addLine(PurchaseOrderLine $line): self
    {
        if (!$this->lines->contains($line)) { $this->lines->add($line); $line->setPurchaseOrder($this); }
        return $this;
    }
    /** @return Collection<int, PurchaseOrderLine> */
    public function getLines(): Collection { return $this->lines; }
    public function refreshReceiptStatus(): void
    {
        if ($this->status === self::STATUS_CANCELLED || $this->status === self::STATUS_DRAFT) { return; }
        $allReceived = true;
        $anyReceived = false;
        foreach ($this->lines as $line) {
            if ((float) $line->getReceivedQuantity() > 0) { $anyReceived = true; }
            if ((float) $line->getReceivedQuantity() + 0.000001 < (float) $line->getQuantity()) { $allReceived = false; }
        }
        $this->status = $allReceived ? self::STATUS_RECEIVED : ($anyReceived ? self::STATUS_PARTIALLY_RECEIVED : self::STATUS_SENT);
    }
    public function getTotalExcludingTax(): float
    {
        return array_reduce($this->lines->toArray(), static fn (float $sum, PurchaseOrderLine $line): float => $sum + $line->getTotalExcludingTax(), 0.0);
    }
    public function getTotalIncludingTax(): float
    {
        return array_reduce($this->lines->toArray(), static fn (float $sum, PurchaseOrderLine $line): float => $sum + $line->getTotalIncludingTax(), 0.0);
    }
}
