<?php

declare(strict_types=1);

namespace App\Entity;

use App\Service\StockQuantity;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'app_sales_invoice_line')]
#[ORM\Index(name: 'idx_sales_invoice_line_invoice', columns: ['invoice_id', 'position'])]
class SalesInvoiceLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SalesInvoice::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'invoice_id', nullable: false, onDelete: 'CASCADE')]
    private ?SalesInvoice $invoice = null;

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

    #[ORM\Column(name: 'credited_quantity', type: 'decimal', precision: 10, scale: 3, options: ['default' => '0.000'])]
    private string $creditedQuantity = '0.000';

    #[ORM\Column]
    private int $position;

    public function __construct(string $description, string $quantity, string $unitPrice, string $vatRate, int $position, ?Product $product = null)
    {
        $description = trim($description);
        if ($description === '' || mb_strlen($description) > 255) {
            throw new InvalidArgumentException('Invoice line description is required (255 characters maximum).');
        }
        $quantityMilliunits = StockQuantity::toMilliunits($quantity);
        if ($quantityMilliunits <= 0 || $quantityMilliunits > 9_999_999_999) {
            throw new InvalidArgumentException('Invoice line quantity must be positive and fit the database precision.');
        }
        if (!is_numeric($unitPrice) || (float) $unitPrice < 0 || (float) $unitPrice > 9999999999.99) {
            throw new InvalidArgumentException('Invoice line unit price is invalid.');
        }
        if (!is_numeric($vatRate) || (float) $vatRate < 0 || (float) $vatRate > 100) {
            throw new InvalidArgumentException('Invoice line VAT rate is invalid.');
        }
        $this->product = $product;
        $this->description = $description;
        $this->quantity = StockQuantity::format($quantityMilliunits);
        $this->unitPrice = number_format((float) $unitPrice, 2, '.', '');
        $this->vatRate = number_format((float) $vatRate, 2, '.', '');
        $this->position = max(1, $position);
    }

    public function getId(): ?int { return $this->id; }
    public function setInvoice(SalesInvoice $invoice): self { $this->invoice = $invoice; return $this; }
    public function getDescription(): string { return $this->description; }
    public function getProduct(): ?Product { return $this->product; }
    public function getQuantity(): string { return $this->quantity; }
    public function getUnitPrice(): string { return $this->unitPrice; }
    public function getVatRate(): string { return $this->vatRate; }
    public function getPosition(): int { return $this->position; }
    public function getCreditedQuantity(): string { return $this->creditedQuantity; }
    public function getCreditEligibleQuantity(): string
    {
        return StockQuantity::format(max(0, StockQuantity::toMilliunits($this->quantity) - StockQuantity::toMilliunits($this->creditedQuantity)));
    }
    public function recordCredit(string $quantity): self
    {
        $quantityMilliunits = StockQuantity::toMilliunits($quantity);
        $eligibleMilliunits = StockQuantity::toMilliunits($this->getCreditEligibleQuantity());
        if ($quantityMilliunits <= 0 || $quantityMilliunits > $eligibleMilliunits) {
            throw new InvalidArgumentException('La quantité de l’avoir dépasse la quantité encore facturable sur cette ligne.');
        }
        $this->creditedQuantity = StockQuantity::format(
            StockQuantity::toMilliunits($this->creditedQuantity) + $quantityMilliunits
        );
        return $this;
    }
    public function getTotalExcludingTax(): float { return round((float) $this->quantity * (float) $this->unitPrice, 2); }
    public function getTotalTax(): float { return round($this->getTotalExcludingTax() * (float) $this->vatRate / 100, 2); }
    public function getTotalIncludingTax(): float { return $this->getTotalExcludingTax() + $this->getTotalTax(); }
}
