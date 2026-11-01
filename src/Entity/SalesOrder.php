<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SalesOrderRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity(repositoryClass: SalesOrderRepository::class)]
#[ORM\Table(name: 'app_sales_order', uniqueConstraints: [
    new ORM\UniqueConstraint(name: 'uniq_sales_order_number', columns: ['number']),
    new ORM\UniqueConstraint(name: 'uniq_sales_order_source_quote', columns: ['source_quote_id']),
])]
#[ORM\Index(name: 'idx_sales_order_customer_status', columns: ['third_party_id', 'status'])]
class SalesOrder
{
    public const STATUS_OPEN = 'open';
    public const STATUS_FULFILLED = 'fulfilled';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $number = null;

    #[ORM\OneToOne(targetEntity: SalesQuote::class)]
    #[ORM\JoinColumn(name: 'source_quote_id', nullable: false, onDelete: 'RESTRICT')]
    private SalesQuote $sourceQuote;

    #[ORM\ManyToOne(targetEntity: ThirdParty::class)]
    #[ORM\JoinColumn(name: 'third_party_id', nullable: false, onDelete: 'RESTRICT')]
    private ThirdParty $customer;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_OPEN;

    #[ORM\Column(name: 'order_date', type: 'date_immutable')]
    private DateTimeImmutable $orderDate;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    /** @var Collection<int, SalesOrderLine> */
    #[ORM\OneToMany(mappedBy: 'order', targetEntity: SalesOrderLine::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $lines;

    public function __construct(SalesQuote $quote)
    {
        if ($quote->getStatus() !== SalesQuote::STATUS_ACCEPTED) {
            throw new InvalidArgumentException('Only accepted quotes can become sales orders.');
        }
        $this->sourceQuote = $quote;
        $this->customer = $quote->getCustomer();
        $this->orderDate = new DateTimeImmutable('today');
        $this->createdAt = new DateTimeImmutable();
        $this->notes = $quote->getNotes();
        $this->lines = new ArrayCollection();

        foreach ($quote->getLines() as $line) {
            $this->addLine(new SalesOrderLine(
                $line->getDescription(),
                $line->getQuantity(),
                $line->getUnitPrice(),
                $line->getVatRate(),
                $line->getPosition(),
                $line->getProduct()
            ));
        }
    }

    public function getId(): ?int { return $this->id; }
    public function getNumber(): ?string { return $this->number; }
    public function assignNumber(): void
    {
        if ($this->id === null) {
            throw new InvalidArgumentException('The order must be persisted before numbering.');
        }
        $this->number = sprintf('CMD-%s-%06d', $this->orderDate->format('Y'), $this->id);
    }
    public function getSourceQuote(): SalesQuote { return $this->sourceQuote; }
    public function getCustomer(): ThirdParty { return $this->customer; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self
    {
        if (!in_array($status, [self::STATUS_OPEN, self::STATUS_FULFILLED, self::STATUS_CANCELLED], true)) {
            throw new InvalidArgumentException('Unsupported order status.');
        }
        $this->status = $status;
        return $this;
    }
    public function getOrderDate(): DateTimeImmutable { return $this->orderDate; }
    public function getNotes(): ?string { return $this->notes; }
    public function addLine(SalesOrderLine $line): self
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setOrder($this);
        }
        return $this;
    }
    /** @return Collection<int, SalesOrderLine> */
    public function getLines(): Collection { return $this->lines; }
    public function getTotalExcludingTax(): float
    {
        return array_reduce($this->lines->toArray(), static fn (float $sum, SalesOrderLine $line): float => $sum + $line->getTotalExcludingTax(), 0.0);
    }
    public function getTotalTax(): float
    {
        return array_reduce($this->lines->toArray(), static fn (float $sum, SalesOrderLine $line): float => $sum + $line->getTotalTax(), 0.0);
    }
    public function getTotalIncludingTax(): float { return $this->getTotalExcludingTax() + $this->getTotalTax(); }
}
