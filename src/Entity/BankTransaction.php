<?php
declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'app_bank_transaction', uniqueConstraints: [
    new ORM\UniqueConstraint(name: 'uniq_bank_transaction_fingerprint', columns: ['fingerprint']),
    new ORM\UniqueConstraint(name: 'uniq_bank_transaction_sales_payment', columns: ['matched_sales_payment_id']),
    new ORM\UniqueConstraint(name: 'uniq_bank_transaction_supplier_payment', columns: ['matched_supplier_payment_id']),
])]
#[ORM\Index(name: 'idx_bank_transaction_date', columns: ['transaction_date'])]
class BankTransaction
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'transaction_date', type: 'date_immutable')]
    private DateTimeImmutable $transactionDate;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $amount;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $reference;

    #[ORM\Column(length: 64)]
    private string $fingerprint;

    #[ORM\ManyToOne(targetEntity: SalesPayment::class)]
    #[ORM\JoinColumn(name: 'matched_sales_payment_id', nullable: true, onDelete: 'RESTRICT')]
    private ?SalesPayment $salesPayment = null;

    #[ORM\ManyToOne(targetEntity: SupplierPayment::class)]
    #[ORM\JoinColumn(name: 'matched_supplier_payment_id', nullable: true, onDelete: 'RESTRICT')]
    private ?SupplierPayment $supplierPayment = null;

    #[ORM\Column(name: 'imported_at', type: 'datetime_immutable')]
    private DateTimeImmutable $importedAt;

    public function __construct(DateTimeImmutable $date, string $label, string $amount, ?string $reference = null)
    {
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 255) {
            throw new InvalidArgumentException('Libellé bancaire invalide.');
        }

        $cents = self::toCents($amount);
        if ($cents === 0) {
            throw new InvalidArgumentException('Une opération bancaire doit avoir un montant non nul.');
        }

        $this->transactionDate = $date;
        $this->label = $label;
        $this->amount = self::fromCents($cents);

        $reference = $reference !== null ? trim($reference) : null;
        $this->reference = $reference !== '' ? $reference : null;
        if ($this->reference !== null && mb_strlen($this->reference) > 120) {
            throw new InvalidArgumentException('Référence bancaire trop longue.');
        }

        $this->fingerprint = hash(
            'sha256',
            $date->format('Y-m-d').'|'.$label.'|'.$this->amount.'|'.($this->reference ?? '')
        );
        $this->importedAt = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTransactionDate(): DateTimeImmutable { return $this->transactionDate; }
    public function getLabel(): string { return $this->label; }
    public function getAmount(): string { return $this->amount; }
    public function getReference(): ?string { return $this->reference; }
    public function getFingerprint(): string { return $this->fingerprint; }
    public function getSalesPayment(): ?SalesPayment { return $this->salesPayment; }
    public function getSupplierPayment(): ?SupplierPayment { return $this->supplierPayment; }

    public function isMatched(): bool
    {
        return $this->salesPayment !== null || $this->supplierPayment !== null;
    }

    public function matchSalesPayment(SalesPayment $payment): self
    {
        if ($this->isMatched() || self::toCents($this->amount) <= 0 || self::toCents($this->amount) !== self::toCents($payment->getAmount())) {
            throw new InvalidArgumentException('Le montant ou le sens du règlement client ne correspond pas.');
        }

        $this->salesPayment = $payment;
        return $this;
    }

    public function matchSupplierPayment(SupplierPayment $payment): self
    {
        if ($this->isMatched() || self::toCents($this->amount) >= 0 || abs(self::toCents($this->amount)) !== self::toCents($payment->getAmount())) {
            throw new InvalidArgumentException('Le montant ou le sens du règlement fournisseur ne correspond pas.');
        }

        $this->supplierPayment = $payment;
        return $this;
    }

    private static function toCents(string $amount): int
    {
        if (!preg_match('/^-?(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/D', $amount)) {
            throw new InvalidArgumentException('Montant bancaire invalide : format décimal attendu, deux décimales maximum.');
        }

        $negative = str_starts_with($amount, '-');
        $unsigned = $negative ? substr($amount, 1) : $amount;
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $cents = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');

        return $negative ? -$cents : $cents;
    }

    private static function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return $sign.intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}
