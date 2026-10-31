<?php

declare(strict_types=1);

namespace App\Entity;

use App\Service\StockQuantity;
use App\Service\AccountingAmount;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'app_sales_quote_line')]
#[ORM\Index(name: 'idx_sales_quote_line_quote', columns: ['quote_id', 'position'])]
class SalesQuoteLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SalesQuote::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'quote_id', nullable: false, onDelete: 'CASCADE')]
    private ?SalesQuote $quote = null;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', nullable: true, onDelete: 'RESTRICT')]
    private ?Product $product = null;

    #[ORM\Column(length: 255)]
    private string $description;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 3)]
    private string $quantity;

    #[ORM\Column(name: 'unit_price', type: 'decimal', precision: 12, scale: 2)]
    private string $unitPrice;

    #[ORM\Column(name: 'vat_rate', type: 'decimal', precision: 5, scale: 2)]
    private string $vatRate;

    #[ORM\Column]
    private int $position;

    public function __construct(string $description, string $quantity, string $unitPrice, string $vatRate, int $position)
    {
        $description = trim($description);
        if ($description === '' || mb_strlen($description) > 255) {
            throw new InvalidArgumentException('Each quote line needs a description of at most 255 characters.');
        }
        $quantityMilliunits = StockQuantity::toMilliunits($quantity);
        if ($quantityMilliunits <= 0 || $quantityMilliunits > 9_999_999_999) {
            throw new InvalidArgumentException('Quantity must be greater than zero and within the supported range.');
        }
        if (!is_numeric($unitPrice) || (float) $unitPrice < 0 || (float) $unitPrice > 9999999999.99) {
            throw new InvalidArgumentException('Unit price is outside the supported range.');
        }
        if (!is_numeric($vatRate) || (float) $vatRate < 0 || (float) $vatRate > 100) {
            throw new InvalidArgumentException('VAT rate must be between 0 and 100.');
        }
        $this->description = $description;
        $this->quantity = StockQuantity::format($quantityMilliunits);
        $this->unitPrice = number_format((float) $unitPrice, 2, '.', '');
        $this->vatRate = number_format((float) $vatRate, 2, '.', '');
        $this->position = max(1, $position);
    }

    public function getId(): ?int { return $this->id; }
    public function setQuote(SalesQuote $quote): self { $this->quote = $quote; return $this; }
    public function getDescription(): string { return $this->description; }
    public function setProduct(?Product $product): self { $this->product = $product; return $this; }
    public function getProduct(): ?Product { return $this->product; }
    public function getQuantity(): string { return $this->quantity; }
    public function getUnitPrice(): string { return $this->unitPrice; }
    public function getVatRate(): string { return $this->vatRate; }
    public function getPosition(): int { return $this->position; }
    public function getTotalExcludingTaxCents(): int
    {
        return AccountingAmount::lineNetCents($this->quantity, $this->unitPrice);
    }

    public function getTotalTaxCents(): int
    {
        return AccountingAmount::taxCents($this->getTotalExcludingTaxCents(), $this->vatRate);
    }

    public function getTotalIncludingTaxCents(): int
    {
        return $this->getTotalExcludingTaxCents() + $this->getTotalTaxCents();
    }

    public function getTotalExcludingTax(): float { return $this->getTotalExcludingTaxCents() / 100; }
    public function getTotalTax(): float { return $this->getTotalTaxCents() / 100; }
    public function getTotalIncludingTax(): float { return $this->getTotalIncludingTaxCents() / 100; }
}
