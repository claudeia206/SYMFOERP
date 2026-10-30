<?php
declare(strict_types=1);
namespace App\Entity;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\Table(name: 'app_bank_transaction', uniqueConstraints: [
    new ORM\UniqueConstraint(name: 'uniq_bank_transaction_fingerprint', columns: ['fingerprint']),
    new ORM\UniqueConstraint(name: 'uniq_bank_transaction_sales_payment', columns: ['matched_sales_payment_id']),
    new ORM\UniqueConstraint(name: 'uniq_bank_transaction_supplier_payment', columns: ['matched_supplier_payment_id']),
])]
#[ORM\Index(name: 'idx_bank_transaction_date', columns: ['transaction_date'])]
class BankTransaction
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id=null;
    #[ORM\Column(name:'transaction_date',type:'date_immutable')] private DateTimeImmutable $transactionDate;
    #[ORM\Column(length:255)] private string $label;
    #[ORM\Column(type:'decimal',precision:12,scale:2)] private string $amount;
    #[ORM\Column(length:120,nullable:true)] private ?string $reference;
    #[ORM\Column(length:64,unique:true)] private string $fingerprint;
    #[ORM\ManyToOne(targetEntity:SalesPayment::class)] #[ORM\JoinColumn(name:'matched_sales_payment_id',nullable:true,onDelete:'RESTRICT')] private ?SalesPayment $salesPayment=null;
    #[ORM\ManyToOne(targetEntity:SupplierPayment::class)] #[ORM\JoinColumn(name:'matched_supplier_payment_id',nullable:true,onDelete:'RESTRICT')] private ?SupplierPayment $supplierPayment=null;
    #[ORM\Column(name:'imported_at',type:'datetime_immutable')] private DateTimeImmutable $importedAt;
    public function __construct(DateTimeImmutable $date,string $label,string $amount,?string $reference=null)
    {
        $label=trim($label); if($label===''||mb_strlen($label)>255||!is_numeric($amount)||round((float)$amount,2)==0||abs((float)$amount)>9999999999.99) throw new \InvalidArgumentException('Transaction bancaire invalide.');
        $this->transactionDate=$date; $this->label=$label; $this->amount=number_format((float)$amount,2,'.','');
        $reference=$reference!==null?trim($reference):null; $this->reference=$reference!==''?$reference:null;
        if($this->reference!==null&&mb_strlen($this->reference)>120) throw new \InvalidArgumentException('Référence bancaire trop longue.');
        $this->fingerprint=hash('sha256',$date->format('Y-m-d')."|".$label."|".$this->amount."|".($this->reference??''));
        $this->importedAt=new DateTimeImmutable();
    }
    public function getId():?int{return $this->id;}
    public function getTransactionDate():DateTimeImmutable{return $this->transactionDate;}
    public function getLabel():string{return $this->label;}
    public function getAmount():string{return $this->amount;}
    public function getReference():?string{return $this->reference;}
    public function getFingerprint():string{return $this->fingerprint;}
    public function getSalesPayment():?SalesPayment{return $this->salesPayment;}
    public function getSupplierPayment():?SupplierPayment{return $this->supplierPayment;}
    public function isMatched():bool{return $this->salesPayment!==null||$this->supplierPayment!==null;}
    public function matchSalesPayment(SalesPayment $payment):self
    {
        if($this->isMatched()||(float)$this->amount<=0||round((float)$this->amount,2)!==round((float)$payment->getAmount(),2)) throw new \InvalidArgumentException('Le montant ou le sens du règlement client ne correspond pas.');
        $this->salesPayment=$payment; return $this;
    }
    public function matchSupplierPayment(SupplierPayment $payment):self
    {
        if($this->isMatched()||(float)$this->amount>=0||round(abs((float)$this->amount),2)!==round((float)$payment->getAmount(),2)) throw new \InvalidArgumentException('Le montant ou le sens du règlement fournisseur ne correspond pas.');
        $this->supplierPayment=$payment; return $this;
    }
}
