<?php
declare(strict_types=1);
namespace App\Entity;
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
        $this->product=$product; $this->description=$description; $this->quantity=$quantity; $this->unitPrice=$unitPrice; $this->vatRate=$vatRate; $this->position=max(1,$position);
    }
    public function getId(): ?int { return $this->id; }
    public function setOrder(SalesOrder $order): self { $this->order=$order; return $this; }
    public function getDescription(): string { return $this->description; }
    public function getProduct(): ?Product { return $this->product; }
    public function getQuantity(): string { return $this->quantity; }
    public function getDeliveredQuantity(): string { return $this->deliveredQuantity; }
    public function getRemainingQuantity(): string { return number_format(max(0,(float)$this->quantity-(float)$this->deliveredQuantity),3,'.',''); }
    public function recordDelivery(string $quantity): self
    {
        if(!is_numeric($quantity)||round((float)$quantity,3)<=0||(float)$quantity>(float)$this->getRemainingQuantity()+0.000001) throw new InvalidArgumentException('La quantité livrée doit être positive et ne peut pas dépasser le reliquat.');
        $this->deliveredQuantity=number_format((float)$this->deliveredQuantity+(float)$quantity,3,'.','');
        return $this;
    }
    public function getReturnedQuantity(): string { return $this->returnedQuantity; }
    public function getReturnableQuantity(): string { return number_format(max(0,(float)$this->deliveredQuantity-(float)$this->returnedQuantity),3,'.',''); }
    public function recordReturn(string $quantity): self
    {
        if(!is_numeric($quantity)||round((float)$quantity,3)<=0||(float)$quantity>(float)$this->getReturnableQuantity()+0.000001) throw new InvalidArgumentException('La quantité retournée dépasse la quantité livrée encore éligible à un retour.');
        $this->returnedQuantity=number_format((float)$this->returnedQuantity+(float)$quantity,3,'.','');
        return $this;
    }
    public function getUnitPrice(): string { return $this->unitPrice; }
    public function getVatRate(): string { return $this->vatRate; }
    public function getTotalExcludingTax(): float { return round((float)$this->quantity*(float)$this->unitPrice,2); }
    public function getTotalTax(): float { return round($this->getTotalExcludingTax()*(float)$this->vatRate/100,2); }
    public function getTotalIncludingTax(): float { return $this->getTotalExcludingTax()+$this->getTotalTax(); }
}
