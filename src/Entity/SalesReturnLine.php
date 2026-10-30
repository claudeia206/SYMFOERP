<?php
declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
#[ORM\Entity]
#[ORM\Table(name: 'app_sales_return_line')]
#[ORM\Index(name: 'idx_sales_return_line_return', columns: ['sales_return_id'])]
class SalesReturnLine
{
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column] private ?int $id=null;
    #[ORM\ManyToOne(targetEntity: SalesReturn::class,inversedBy: 'lines')] #[ORM\JoinColumn(name: 'sales_return_id',nullable:false,onDelete:'CASCADE')] private ?SalesReturn $salesReturn=null;
    #[ORM\ManyToOne(targetEntity: SalesOrderLine::class)] #[ORM\JoinColumn(name: 'sales_order_line_id',nullable:false,onDelete:'RESTRICT')] private SalesOrderLine $orderLine;
    #[ORM\Column(length:255)] private string $description;
    #[ORM\Column(type:'decimal',precision:10,scale:3)] private string $quantity;
    public function __construct(SalesOrderLine $orderLine,string $quantity)
    {
        if(!is_numeric($quantity)||round((float)$quantity,3)<=0||(float)$quantity>(float)$orderLine->getReturnableQuantity()+0.000001) throw new InvalidArgumentException('La quantité retournée doit être positive et ne peut pas dépasser la quantité déjà livrée non retournée.');
        $this->orderLine=$orderLine; $this->description=$orderLine->getDescription(); $this->quantity=number_format((float)$quantity,3,'.','');
    }
    public function getId(): ?int { return $this->id; }
    public function setSalesReturn(SalesReturn $salesReturn): self { $this->salesReturn=$salesReturn; return $this; }
    public function getOrderLine(): SalesOrderLine { return $this->orderLine; }
    public function getDescription(): string { return $this->description; }
    public function getQuantity(): string { return $this->quantity; }
}
