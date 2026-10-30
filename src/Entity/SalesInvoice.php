<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SalesInvoiceRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity(repositoryClass: SalesInvoiceRepository::class)]
#[ORM\Table(name: 'app_sales_invoice', uniqueConstraints: [
    new ORM\UniqueConstraint(name: 'uniq_sales_invoice_number', columns: ['number']),
    new ORM\UniqueConstraint(name: 'uniq_sales_invoice_order', columns: ['sales_order_id']),
])]
#[ORM\Index(name: 'idx_sales_invoice_customer_status', columns: ['third_party_id', 'status'])]
class SalesInvoice
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ISSUED = 'issued';
    public const STATUS_PARTIALLY_PAID = 'partially_paid';
    public const STATUS_PAID = 'paid';
    public const STATUS_OVERDUE = 'overdue';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $number = null;

    #[ORM\OneToOne(targetEntity: SalesOrder::class)]
    #[ORM\JoinColumn(name: 'sales_order_id', nullable: false, onDelete: 'RESTRICT')]
    private SalesOrder $order;

    #[ORM\ManyToOne(targetEntity: ThirdParty::class)]
    #[ORM\JoinColumn(name: 'third_party_id', nullable: false, onDelete: 'RESTRICT')]
    private ThirdParty $customer;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(name: 'invoice_date', type: 'date_immutable')]
    private DateTimeImmutable $invoiceDate;

    #[ORM\Column(name: 'due_date', type: 'date_immutable')]
    private DateTimeImmutable $dueDate;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'issued_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $issuedAt = null;

    #[ORM\Column(name: 'seller_snapshot', type: 'json', nullable: true)]
    private ?array $sellerSnapshot = null;

    #[ORM\Column(name: 'customer_snapshot', type: 'json', nullable: true)]
    private ?array $customerSnapshot = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    /** @var Collection<int, SalesInvoiceLine> */
    #[ORM\OneToMany(mappedBy: 'invoice', targetEntity: SalesInvoiceLine::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $lines;

    /** @var Collection<int, SalesPayment> */
    #[ORM\OneToMany(mappedBy: 'invoice', targetEntity: SalesPayment::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['paidAt' => 'DESC', 'id' => 'DESC'])]
    private Collection $payments;

    public function __construct(SalesOrder $order)
    {
        if ($order->getStatus() === SalesOrder::STATUS_CANCELLED) {
            throw new InvalidArgumentException('A cancelled order cannot be invoiced.');
        }
        $this->order = $order;
        $this->customer = $order->getCustomer();
        $this->invoiceDate = new DateTimeImmutable('today');
        $this->dueDate = new DateTimeImmutable('+30 days');
        $this->notes = $order->getNotes();
        $this->createdAt = new DateTimeImmutable();
        $this->lines = new ArrayCollection();
        $this->payments = new ArrayCollection();

        foreach ($order->getLines() as $line) {
            $this->addLine(new SalesInvoiceLine($line->getDescription(), $line->getQuantity(), $line->getUnitPrice(), $line->getVatRate(), $line->getPosition(), $line->getProduct()));
        }
        if ($this->lines->isEmpty()) {
            throw new InvalidArgumentException('An order without lines cannot be invoiced.');
        }
    }

    public function getId(): ?int { return $this->id; }
    public function getNumber(): ?string { return $this->number; }
    public function getOrder(): SalesOrder { return $this->order; }
    public function getCustomer(): ThirdParty { return $this->customer; }
    public function getStatus(): string { return $this->status; }
    public function getInvoiceDate(): DateTimeImmutable { return $this->invoiceDate; }
    public function getDueDate(): DateTimeImmutable { return $this->dueDate; }
    public function getNotes(): ?string { return $this->notes; }
    public function getIssuedAt(): ?DateTimeImmutable { return $this->issuedAt; }
    public function getSellerSnapshot(): ?array { return $this->sellerSnapshot; }
    public function getCustomerSnapshot(): ?array { return $this->customerSnapshot; }

    public function issue(string $number, DateTimeImmutable $issuedAt, DateTimeImmutable $dueDate, array $sellerSnapshot, array $customerSnapshot): self
    {
        if ($this->status !== self::STATUS_DRAFT || $this->number !== null) {
            throw new InvalidArgumentException('Only a draft invoice can be issued once.');
        }
        if (!preg_match('/^FAC-\\d{4}-\\d{6}$/', $number)) {
            throw new InvalidArgumentException('Invalid invoice number format.');
        }
        if (trim((string) ($customerSnapshot['name'] ?? '')) === '' || trim((string) ($customerSnapshot['address'] ?? '')) === '' || trim((string) ($customerSnapshot['postal_code'] ?? '')) === '' || trim((string) ($customerSnapshot['city'] ?? '')) === '') {
            throw new InvalidArgumentException('Customer billing identity and address are incomplete.');
        }
        $this->number = $number;
        $this->issuedAt = $issuedAt;
        $this->invoiceDate = $issuedAt;
        $this->dueDate = $dueDate;
        $this->sellerSnapshot = $sellerSnapshot;
        $this->customerSnapshot = $customerSnapshot;
        $this->status = self::STATUS_ISSUED;
        return $this;
    }

    public function addLine(SalesInvoiceLine $line): self
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setInvoice($this);
        }
        return $this;
    }

    /** @return Collection<int, SalesInvoiceLine> */
    public function getLines(): Collection { return $this->lines; }

    /** @return Collection<int, SalesPayment> */
    public function getPayments(): Collection { return $this->payments; }

    public function addPayment(SalesPayment $payment): self
    {
        if ($payment->getInvoice() !== $this) {
            throw new InvalidArgumentException('Payment belongs to another invoice.');
        }
        if ($this->status !== self::STATUS_ISSUED && $this->status !== self::STATUS_PARTIALLY_PAID) {
            throw new InvalidArgumentException('Only issued invoices can receive payments.');
        }
        if ($this->getRemainingAmount() <= 0) {
            throw new InvalidArgumentException('This invoice has no remaining balance.');
        }
        if ((float) $payment->getAmount() > $this->getRemainingAmount() + 0.001) {
            throw new InvalidArgumentException('Payment exceeds the remaining invoice balance.');
        }
        if (!$this->payments->contains($payment)) {
            $this->payments->add($payment);
        }
        $this->status = $this->getRemainingAmount() <= 0.001 ? self::STATUS_PAID : self::STATUS_PARTIALLY_PAID;
        return $this;
    }

    public function getTotalExcludingTax(): float
    {
        return array_reduce($this->lines->toArray(), static fn (float $sum, SalesInvoiceLine $line): float => $sum + $line->getTotalExcludingTax(), 0.0);
    }

    public function getTotalTax(): float
    {
        return array_reduce($this->lines->toArray(), static fn (float $sum, SalesInvoiceLine $line): float => $sum + $line->getTotalTax(), 0.0);
    }

    public function getTotalIncludingTax(): float { return round($this->getTotalExcludingTax() + $this->getTotalTax(), 2); }

    public function getPaidAmount(): float
    {
        return round(array_reduce($this->payments->toArray(), static fn (float $sum, SalesPayment $payment): float => $sum + (float) $payment->getAmount(), 0.0), 2);
    }

    public function getRemainingAmount(): float
    {
        return max(0.0, round($this->getTotalIncludingTax() - $this->getPaidAmount(), 2));
    }

    public function getEffectiveStatus(?DateTimeImmutable $today = null): string
    {
        if (in_array($this->status, [self::STATUS_ISSUED, self::STATUS_PARTIALLY_PAID], true) && $this->getRemainingAmount() > 0 && $this->dueDate < ($today ?? new DateTimeImmutable('today'))) {
            return self::STATUS_OVERDUE;
        }
        return $this->status;
    }
}
