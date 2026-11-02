<?php
declare(strict_types=1);
namespace App\Entity;
use App\Service\StockQuantity;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
#[ORM\Entity]
#[ORM\Table(name: 'app_sales_delivery_line')]
#[ORM\Index(name: 'idx_sales_delivery_line_delivery', columns: ['delivery_id'])]
class SalesDeliveryLine
{
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column] private ?int $id=null;
    #[ORM\ManyToOne(targetEntity: SalesDelivery::class, inversedBy: 'lines')] #[ORM\JoinColumn(name: 'delivery_id', nullable: false, onDelete: 'CASCADE')] private ?SalesDelivery $delivery=null;
    #[ORM\ManyToOne(targetEntity: SalesOrderLine::class)] #[ORM\JoinColumn(name: 'sales_order_line_id', nullable: false, onDelete: 'RESTRICT')] private SalesOrderLine $orderLine;
    #[ORM\Column(length: 255)] private string $description;
    #[ORM\Column(type: 'decimal', precision: 10, scale: 3)] private string $quantity;
    public function __construct(SalesOrderLine $orderLine,string $quantity)
    {
        $milliunits = StockQuantity::toMilliunits($quantity);
        if ($milliunits <= 0 || $milliunits > StockQuantity::toMilliunits($orderLine->getRemainingQuantity())) throw new InvalidArgumentException('La quantité livrée doit être positive et ne peut pas dépasser le reliquat.');
        $this->orderLine=$orderLine; $this->description=$orderLine->getDescription(); $this->quantity=StockQuantity::format($milliunits);
    }
    public function getId(): ?int { return $this->id; }
    public function setDelivery(SalesDelivery $delivery): self { $this->delivery=$delivery; return $this; }
    public function getOrderLine(): SalesOrderLine { return $this->orderLine; }
    public function getDescription(): string { return $this->description; }
    public function getQuantity(): string { return $this->quantity; }
}
