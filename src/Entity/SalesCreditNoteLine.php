<?php
declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
#[ORM\Entity]
#[ORM\Table(name: 'app_sales_credit_note_line')]
#[ORM\Index(name: 'idx_sales_credit_note_line_note', columns: ['credit_note_id'])]
class SalesCreditNoteLine
{
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column] private ?int $id=null;
    #[ORM\ManyToOne(targetEntity: SalesCreditNote::class,inversedBy:'lines')]
    #[ORM\JoinColumn(name:'credit_note_id',nullable:false,onDelete:'CASCADE')]
    private SalesCreditNote $creditNote;
    #[ORM\ManyToOne(targetEntity: SalesInvoiceLine::class)]
    #[ORM\JoinColumn(name:'invoice_line_id',nullable:false,onDelete:'RESTRICT')]
    private SalesInvoiceLine $invoiceLine;
    #[ORM\Column(length:255)] private string $description;
    #[ORM\Column(type:'decimal',precision:10,scale:3)] private string $quantity;
    #[ORM\Column(name:'unit_price',type:'decimal',precision:12,scale:2)] private string $unitPrice;
    #[ORM\Column(name:'vat_rate',type:'decimal',precision:5,scale:2)] private string $vatRate;
    public function __construct(SalesCreditNote $creditNote,SalesInvoiceLine $invoiceLine,string $quantity)
    {
        if(!preg_match('/^(?:0|[1-9]\\d{0,6})(?:\\.\\d{1,3})?$/D',$quantity)||(float)$quantity<=0) throw new InvalidArgumentException('La quantité de l’avoir doit être un nombre décimal positif avec trois décimales maximum.');
        $this->creditNote=$creditNote; $this->invoiceLine=$invoiceLine; $this->description=$invoiceLine->getDescription();
        $this->quantity=number_format((float)$quantity,3,'.',''); $this->unitPrice=$invoiceLine->getUnitPrice(); $this->vatRate=$invoiceLine->getVatRate();
    }
    public function getId(): ?int { return $this->id; }
    public function getCreditNote(): SalesCreditNote { return $this->creditNote; }
    public function getDescription(): string { return $this->description; }
    public function getQuantity(): string { return $this->quantity; }
    public function getUnitPrice(): string { return $this->unitPrice; }
    public function getVatRate(): string { return $this->vatRate; }
    public function getTotalExcludingTax(): float { return round((float)$this->quantity*(float)$this->unitPrice,2); }
    public function getTotalTax(): float { return round($this->getTotalExcludingTax()*(float)$this->vatRate/100,2); }
    public function getTotalIncludingTax(): float { return round($this->getTotalExcludingTax()+$this->getTotalTax(),2); }
}
