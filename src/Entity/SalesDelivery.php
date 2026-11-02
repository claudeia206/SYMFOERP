<?php
declare(strict_types=1);
namespace App\Entity;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
#[ORM\Entity]
#[ORM\Table(name: 'app_sales_delivery', uniqueConstraints: [new ORM\UniqueConstraint(name: 'uniq_sales_delivery_number', columns: ['number'])])]
#[ORM\Index(name: 'idx_sales_delivery_order_date', columns: ['sales_order_id', 'delivery_date'])]
class SalesDelivery
{
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne(targetEntity: SalesOrder::class)] #[ORM\JoinColumn(name: 'sales_order_id', nullable: false, onDelete: 'RESTRICT')] private SalesOrder $order;
    #[ORM\Column(length: 32, nullable: true)] private ?string $number = null;
    #[ORM\Column(name: 'delivery_date', type: 'date_immutable')] private DateTimeImmutable $deliveryDate;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $notes = null;
    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')] private DateTimeImmutable $createdAt;
    /** @var Collection<int, SalesDeliveryLine> */
    #[ORM\OneToMany(mappedBy: 'delivery', targetEntity: SalesDeliveryLine::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])] private Collection $lines;
    public function __construct(SalesOrder $order, ?string $notes = null)
    {
        if ($order->getStatus() !== SalesOrder::STATUS_OPEN) throw new InvalidArgumentException('Seules les commandes en cours peuvent être livrées.');
        $this->order=$order; $this->deliveryDate=new DateTimeImmutable('today'); $this->createdAt=new DateTimeImmutable();
        $this->notes=trim((string)$notes)!==''?mb_substr(trim((string)$notes),0,2000):null; $this->lines=new ArrayCollection();
    }
    public function getId(): ?int { return $this->id; }
    public function getOrder(): SalesOrder { return $this->order; }
    public function getNumber(): ?string { return $this->number; }
    public function getDeliveryDate(): DateTimeImmutable { return $this->deliveryDate; }
    public function getNotes(): ?string { return $this->notes; }
    public function assignNumber(): void { if($this->id===null) throw new InvalidArgumentException('Le bon de livraison doit être enregistré avant numérotation.'); $this->number=sprintf('BL-%s-%06d',$this->deliveryDate->format('Y'),$this->id); }
    public function addLine(SalesDeliveryLine $line): self { if(!$this->lines->contains($line)){ $this->lines->add($line); $line->setDelivery($this); } return $this; }
    /** @return Collection<int, SalesDeliveryLine> */ public function getLines(): Collection { return $this->lines; }
}
