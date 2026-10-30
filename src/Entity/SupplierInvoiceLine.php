<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'app_supplier_invoice_line')]
#[ORM\Index(name: 'idx_supplier_invoice_line_invoice', columns: ['invoice_id', 'position'])]
class SupplierInvoiceLine
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SupplierInvoice::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'invoice_id', nullable: false, onDelete: 'CASCADE')]
    private ?SupplierInvoice $invoice = null;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', nullable: false, onDelete: 'RESTRICT')]
    private Product $product;

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

    public function __construct(Product $product, string $description, string $quantity, string $unitPrice, string $vatRate, int $position)
    {
        $description = trim($description);
        if ($description === '' || mb_strlen($description) > 255 || !is_numeric($quantity) || (float) $quantity <= 0 || !is_numeric($unitPrice) || (float) $unitPrice < 0 || !is_numeric($vatRate) || (float) $vatRate < 0 || (float) $vatRate > 100) {
            throw new InvalidArgumentException('Ligne de facture fournisseur invalide.');
        }
        $this->product = $product;
        $this->description = $description;
        $this->quantity = number_format((float) $quantity, 3, '.', '');
        $this->unitPrice = number_format((float) $unitPrice, 2, '.', '');
        $this->vatRate = number_format((float) $vatRate, 2, '.', '');
        $this->position = max(1, $position);
    }
    public function getId(): ?int { return $this->id; }
    public function setInvoice(SupplierInvoice $invoice): self { $this->invoice = $invoice; return $this; }
    public function getProduct(): Product { return $this->product; }
    public function getDescription(): string { return $this->description; }
    public function getQuantity(): string { return $this->quantity; }
    public function getUnitPrice(): string { return $this->unitPrice; }
    public function getVatRate(): string { return $this->vatRate; }
    public function getPosition(): int { return $this->position; }
    public function getTotalExcludingTax(): float { return round((float) $this->quantity * (float) $this->unitPrice, 2); }
    public function getTotalTax(): float { return round($this->getTotalExcludingTax() * (float) $this->vatRate / 100, 2); }
    public function getTotalIncludingTax(): float { return $this->getTotalExcludingTax() + $this->getTotalTax(); }
}
