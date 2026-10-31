<?php
declare(strict_types=1);
namespace App\Entity;
use App\Service\StockQuantity;
use App\Service\AccountingAmount;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
#[ORM\Entity]
#[ORM\Table(name: 'app_sales_order_line')]
#[ORM\Index(name: 'idx_sales_order_line_order', columns: ['order_id', 'position'])]
class SalesOrderLine
{
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column] private ?int $id=null;
    #[ORM\ManyToOne(targetEntity: SalesOrder::class, inversedBy: 'lines')] #[ORM\JoinColumn(name: 'order_id', nullable: false, onDelete: 'CASCADE')] private ?SalesOrder $order=null;
    #[ORM\ManyToOne(targetEntity: Product::class)] #[ORM\JoinColumn(name: 'product_id', nullable: true, onDelete: 'RESTRICT')] private ?Product $product=null;
    #[ORM\Column(length: 255)] private string $description;
    #[ORM\Column(type: 'decimal', precision: 10, scale: 3)] private string $quantity;
    #[ORM\Column(name: 'delivered_quantity', type: 'decimal', precision: 10, scale: 3, options: ['default' => '0.000'])] private string $deliveredQuantity='0.000';
    #[ORM\Column(name: 'returned_quantity', type: 'decimal', precision: 10, scale: 3, options: ['default' => '0.000'])] private string $returnedQuantity='0.000';
    #[ORM\Column(name: 'unit_price', type: 'decimal', precision: 12, scale: 2)] private string $unitPrice;
    #[ORM\Column(name: 'vat_rate', type: 'decimal', precision: 5, scale: 2)] private string $vatRate;
    #[ORM\Column] private int $position;
    public function __construct(string $description,string $quantity,string $unitPrice,string $vatRate,int $position,?Product $product=null)
    {
        $milliunits=StockQuantity::toMilliunits($quantity);
        $unitPriceCents=AccountingAmount::toCents($unitPrice);
        $vatRateCents=AccountingAmount::toCents($vatRate);
        if($milliunits<=0||$milliunits>9999999999) throw new InvalidArgumentException('La quantité commandée doit être strictement positive.');
        if($unitPriceCents<0||$unitPriceCents>999999999999||$vatRateCents<0||$vatRateCents>10000) throw new InvalidArgumentException('Le prix unitaire ou le taux de TVA est invalide.');
        $this->product=$product; $this->description=$description; $this->quantity=StockQuantity::format($milliunits);
        $this->unitPrice=AccountingAmount::formatCents($unitPriceCents); $this->vatRate=AccountingAmount::formatCents($vatRateCents); $this->position=max(1,$position);
    }
    public function getId(): ?int { return $this->id; }
    public function getPosition(): int { return $this->position; }
    public function setOrder(SalesOrder $order): self { $this->order=$order; return $this; }
    public function getDescription(): string { return $this->description; }
    public function getProduct(): ?Product { return $this->product; }
    public function getQuantity(): string { return $this->quantity; }
    public function getDeliveredQuantity(): string { return $this->deliveredQuantity; }
    public function getRemainingQuantity(): string { return StockQuantity::format(max(0,StockQuantity::toMilliunits($this->quantity)-StockQuantity::toMilliunits($this->deliveredQuantity))); }
    public function recordDelivery(string $quantity): self
    {
        $delivered=StockQuantity::toMilliunits($quantity); if($delivered<=0||$delivered>StockQuantity::toMilliunits($this->getRemainingQuantity())) throw new InvalidArgumentException('La quantité livrée doit être positive et ne peut pas dépasser le reliquat.');
        $this->deliveredQuantity=StockQuantity::format(StockQuantity::toMilliunits($this->deliveredQuantity)+$delivered);
        return $this;
    }
    public function getReturnedQuantity(): string { return $this->returnedQuantity; }
    public function getReturnableQuantity(): string { return StockQuantity::format(max(0,StockQuantity::toMilliunits($this->deliveredQuantity)-StockQuantity::toMilliunits($this->returnedQuantity))); }
    public function recordReturn(string $quantity): self
    {
        $returned=StockQuantity::toMilliunits($quantity); if($returned<=0||$returned>StockQuantity::toMilliunits($this->getReturnableQuantity())) throw new InvalidArgumentException('La quantité retournée dépasse la quantité livrée encore éligible à un retour.');
        $this->returnedQuantity=StockQuantity::format(StockQuantity::toMilliunits($this->returnedQuantity)+$returned);
        return $this;
    }
    public function getUnitPrice(): string { return $this->unitPrice; }
    public function getVatRate(): string { return $this->vatRate; }
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
