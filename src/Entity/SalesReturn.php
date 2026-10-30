<?php
declare(strict_types=1);
namespace App\Entity;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
#[ORM\Entity]
#[ORM\Table(name: 'app_sales_return', uniqueConstraints: [new ORM\UniqueConstraint(name: 'uniq_sales_return_number', columns: ['number'])])]
#[ORM\Index(name: 'idx_sales_return_order_date', columns: ['sales_order_id', 'return_date'])]
class SalesReturn
{
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column] private ?int $id=null;
    #[ORM\ManyToOne(targetEntity: SalesOrder::class)] #[ORM\JoinColumn(name: 'sales_order_id', nullable: false, onDelete: 'RESTRICT')] private SalesOrder $order;
    #[ORM\Column(length: 32, nullable: true)] private ?string $number=null;
    #[ORM\Column(name: 'return_date', type: 'date_immutable')] private DateTimeImmutable $returnDate;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $notes=null;
    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')] private DateTimeImmutable $createdAt;
    /** @var Collection<int, SalesReturnLine> */
    #[ORM\OneToMany(mappedBy: 'salesReturn', targetEntity: SalesReturnLine::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id'=>'ASC'])] private Collection $lines;
    public function __construct(SalesOrder $order,?string $notes=null)
    {
        $this->order=$order; $this->returnDate=new DateTimeImmutable('today'); $this->createdAt=new DateTimeImmutable();
        $this->notes=trim((string)$notes)!==''?mb_substr(trim((string)$notes),0,2000):null; $this->lines=new ArrayCollection();
    }
    public function getId(): ?int { return $this->id; }
    public function getOrder(): SalesOrder { return $this->order; }
    public function getNumber(): ?string { return $this->number; }
    public function getReturnDate(): DateTimeImmutable { return $this->returnDate; }
    public function getNotes(): ?string { return $this->notes; }
    public function assignNumber(): void { if($this->id===null) throw new InvalidArgumentException('Le retour doit être enregistré avant numérotation.'); $this->number=sprintf('RET-%s-%06d',$this->returnDate->format('Y'),$this->id); }
    public function addLine(SalesReturnLine $line): self { if(!$this->lines->contains($line)){ $this->lines->add($line); $line->setSalesReturn($this); } return $this; }
    /** @return Collection<int, SalesReturnLine> */ public function getLines(): Collection { return $this->lines; }
}
