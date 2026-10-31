<?php
declare(strict_types=1);

namespace App\Entity;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
#[ORM\Entity]
#[ORM\Table(name: 'app_sales_credit_note', uniqueConstraints: [new ORM\UniqueConstraint(name: 'uniq_sales_credit_note_number', columns: ['number'])])]
#[ORM\Index(name: 'idx_sales_credit_note_invoice_date', columns: ['invoice_id', 'credit_date'])]
class SalesCreditNote
{
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne(targetEntity: SalesInvoice::class, inversedBy: 'creditNotes')]
    #[ORM\JoinColumn(name: 'invoice_id', nullable: false, onDelete: 'RESTRICT')]
    private SalesInvoice $invoice;
    #[ORM\Column(length: 32, nullable: true)] private ?string $number = null;
    #[ORM\Column(name: 'credit_date', type: 'date_immutable')] private DateTimeImmutable $creditDate;
    #[ORM\Column(type: 'text')] private string $reason;
    #[ORM\Column(name: 'seller_snapshot', type: 'json')] private array $sellerSnapshot;
    #[ORM\Column(name: 'customer_snapshot', type: 'json')] private array $customerSnapshot;
    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')] private DateTimeImmutable $createdAt;
    /** @var Collection<int, SalesCreditNoteLine> */
    #[ORM\OneToMany(mappedBy: 'creditNote', targetEntity: SalesCreditNoteLine::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lines;
    public function __construct(SalesInvoice $invoice, string $reason, array $sellerSnapshot, array $customerSnapshot)
    {
        if ($invoice->getNumber() === null || in_array($invoice->getStatus(), [SalesInvoice::STATUS_DRAFT, SalesInvoice::STATUS_CANCELLED], true)) throw new InvalidArgumentException('Un avoir ne peut être créé que sur une facture émise.');
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 2000) throw new InvalidArgumentException('Le motif de l’avoir est obligatoire (2 000 caractères maximum).');
        $this->invoice=$invoice; $this->reason=$reason; $this->sellerSnapshot=$sellerSnapshot; $this->customerSnapshot=$customerSnapshot;
        $this->creditDate=new DateTimeImmutable('today'); $this->createdAt=new DateTimeImmutable(); $this->lines=new ArrayCollection();
    }
    public function getId(): ?int { return $this->id; }
    public function getInvoice(): SalesInvoice { return $this->invoice; }
    public function getNumber(): ?string { return $this->number; }
    public function getCreditDate(): DateTimeImmutable { return $this->creditDate; }
    public function getReason(): string { return $this->reason; }
    public function getSellerSnapshot(): array { return $this->sellerSnapshot; }
    public function getCustomerSnapshot(): array { return $this->customerSnapshot; }
    /** @return Collection<int, SalesCreditNoteLine> */ public function getLines(): Collection { return $this->lines; }
    public function assignNumber(string $number): self
    {
        if ($this->id===null || $this->number!==null || !preg_match('/^AV-\d{4}-\d{6}$/',$number)) throw new InvalidArgumentException('Numéro d’avoir invalide ou déjà attribué.');
        $this->number=$number; return $this;
    }
    public function addLine(SalesCreditNoteLine $line): self
    {
        if ($line->getCreditNote()!==$this) throw new InvalidArgumentException('La ligne appartient à un autre avoir.');
        if (!$this->lines->contains($line)) $this->lines->add($line); return $this;
    }
    public function getTotalExcludingTaxCents(): int
    {
        return array_reduce($this->lines->toArray(), static fn (int $sum, SalesCreditNoteLine $line): int => $sum + $line->getTotalExcludingTaxCents(), 0);
    }

    public function getTotalTaxCents(): int
    {
        return array_reduce($this->lines->toArray(), static fn (int $sum, SalesCreditNoteLine $line): int => $sum + $line->getTotalTaxCents(), 0);
    }

    public function getTotalIncludingTaxCents(): int
    {
        return $this->getTotalExcludingTaxCents() + $this->getTotalTaxCents();
    }

    public function getTotalExcludingTax(): float { return $this->getTotalExcludingTaxCents() / 100; }
    public function getTotalTax(): float { return $this->getTotalTaxCents() / 100; }
    public function getTotalIncludingTax(): float { return $this->getTotalIncludingTaxCents() / 100; }
}
