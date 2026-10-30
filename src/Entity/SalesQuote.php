<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SalesQuoteRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity(repositoryClass: SalesQuoteRepository::class)]
#[ORM\Table(name: 'app_sales_quote', uniqueConstraints: [new ORM\UniqueConstraint(name: 'uniq_sales_quote_number', columns: ['number'])])]
#[ORM\Index(name: 'idx_sales_quote_customer_status', columns: ['third_party_id', 'status'])]
class SalesQuote
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $number = null;

    #[ORM\ManyToOne(targetEntity: ThirdParty::class)]
    #[ORM\JoinColumn(name: 'third_party_id', nullable: false, onDelete: 'RESTRICT')]
    private ThirdParty $customer;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(name: 'quote_date', type: 'date_immutable')]
    private DateTimeImmutable $quoteDate;

    #[ORM\Column(name: 'valid_until', type: 'date_immutable')]
    private DateTimeImmutable $validUntil;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    /** @var Collection<int, SalesQuoteLine> */
    #[ORM\OneToMany(mappedBy: 'quote', targetEntity: SalesQuoteLine::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $lines;

    public function __construct(ThirdParty $customer)
    {
        if ($customer->getCategory() !== ThirdParty::CATEGORY_CUSTOMER) {
            throw new InvalidArgumentException('A sales quote must be linked to a customer.');
        }
        $this->customer = $customer;
        $this->quoteDate = new DateTimeImmutable('today');
        $this->validUntil = new DateTimeImmutable('+30 days');
        $this->createdAt = new DateTimeImmutable();
        $this->lines = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getNumber(): ?string { return $this->number; }
    public function assignNumber(): void
    {
        if ($this->id === null) {
            throw new InvalidArgumentException('The quote must be persisted before numbering.');
        }
        $this->number = sprintf('DEV-%s-%06d', $this->quoteDate->format('Y'), $this->id);
    }
    public function getCustomer(): ThirdParty { return $this->customer; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self
    {
        if (!in_array($status, [self::STATUS_DRAFT, self::STATUS_SENT, self::STATUS_ACCEPTED, self::STATUS_REJECTED], true)) {
            throw new InvalidArgumentException('Unsupported quote status.');
        }
        $this->status = $status;
        return $this;
    }
    public function getQuoteDate(): DateTimeImmutable { return $this->quoteDate; }
    public function getValidUntil(): DateTimeImmutable { return $this->validUntil; }
    public function setValidUntil(DateTimeImmutable $date): self
    {
        if ($date < $this->quoteDate) {
            throw new InvalidArgumentException('The validity date cannot precede the quote date.');
        }
        $this->validUntil = $date;
        return $this;
    }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self
    {
        $notes = trim((string) $notes);
        $this->notes = $notes !== '' ? mb_substr($notes, 0, 5000) : null;
        return $this;
    }

    public function addLine(SalesQuoteLine $line): self
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setQuote($this);
        }
        return $this;
    }

    /** @return Collection<int, SalesQuoteLine> */
    public function getLines(): Collection { return $this->lines; }

    public function getTotalExcludingTax(): float
    {
        return array_reduce($this->lines->toArray(), static fn (float $sum, SalesQuoteLine $line): float => $sum + $line->getTotalExcludingTax(), 0.0);
    }

    public function getTotalTax(): float
    {
        return array_reduce($this->lines->toArray(), static fn (float $sum, SalesQuoteLine $line): float => $sum + $line->getTotalTax(), 0.0);
    }

    public function getTotalIncludingTax(): float
    {
        return $this->getTotalExcludingTax() + $this->getTotalTax();
    }
}
