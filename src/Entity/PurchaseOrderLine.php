<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'app_purchase_order_line')]
#[ORM\Index(name: 'idx_purchase_order_line_order', columns: ['purchase_order_id', 'position'])]
class PurchaseOrderLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PurchaseOrder::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'purchase_order_id', nullable: false, onDelete: 'CASCADE')]
    private ?PurchaseOrder $purchaseOrder = null;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', nullable: false, onDelete: 'RESTRICT')]
    private Product $product;

    #[ORM\Column(length: 255)]
    private string $description;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 3)]
    private string $quantity;

    #[ORM\Column(name: 'received_quantity', type: 'decimal', precision: 10, scale: 3)]
    private string $receivedQuantity = '0.000';

    #[ORM\Column(name: 'unit_price', type: 'decimal', precision: 12, scale: 2)]
    private string $unitPrice;

    #[ORM\Column(name: 'vat_rate', type: 'decimal', precision: 5, scale: 2)]
    private string $vatRate;

    #[ORM\Column]
    private int $position;

    public function __construct(Product $product, string $quantity, int $position)
    {
        if (!$product->isActive()) { throw new InvalidArgumentException('Un produit archivé ne peut pas être commandé.'); }
        if (!is_numeric($quantity) || round((float) $quantity, 3) <= 0 || (float) $quantity > 9999999.999) {
            throw new InvalidArgumentException('La quantité commandée doit être strictement positive.');
        }
        $this->product = $product;
        $this->description = $product->getName();
        $this->quantity = number_format((float) $quantity, 3, '.', '');
        $this->unitPrice = $product->getPurchasePrice();
        $this->vatRate = $product->getVatRate();
        $this->position = max(1, $position);
    }

    public function getId(): ?int { return $this->id; }
    public function setPurchaseOrder(PurchaseOrder $order): self { $this->purchaseOrder = $order; return $this; }
    public function getProduct(): Product { return $this->product; }
    public function getDescription(): string { return $this->description; }
    public function getQuantity(): string { return $this->quantity; }
    public function getReceivedQuantity(): string { return $this->receivedQuantity; }
    public function getRemainingQuantity(): string { return number_format(max(0, (float) $this->quantity - (float) $this->receivedQuantity), 3, '.', ''); }
    public function getUnitPrice(): string { return $this->unitPrice; }
    public function getVatRate(): string { return $this->vatRate; }
    public function getTotalExcludingTax(): float { return (float) $this->quantity * (float) $this->unitPrice; }
    public function getTotalIncludingTax(): float { return $this->getTotalExcludingTax() * (1 + (float) $this->vatRate / 100); }
    public function receive(string $quantity): self
    {
        if (!is_numeric($quantity) || round((float) $quantity, 3) <= 0 || (float) $quantity > (float) $this->getRemainingQuantity() + 0.000001) {
            throw new InvalidArgumentException('La quantité réceptionnée doit être positive et ne peut pas dépasser le reliquat.');
        }
        $this->receivedQuantity = number_format((float) $this->receivedQuantity + (float) $quantity, 3, '.', '');
        return $this;
    }
}
