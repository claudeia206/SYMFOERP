<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'app_product_stock_movement')]
#[ORM\Index(name: 'idx_product_stock_movement_product_date', columns: ['product_id', 'created_at'])]
class ProductStockMovement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', nullable: false, onDelete: 'RESTRICT')]
    private Product $product;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 3)]
    private string $quantity;

    #[ORM\Column(length: 40)]
    private string $reason;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(Product $product, string $quantity, string $reason, ?string $note = null)
    {
        if (!is_numeric($quantity) || (float) $quantity == 0.0) {
            throw new InvalidArgumentException('La quantité du mouvement doit être non nulle.');
        }
        $reason = trim($reason);
        if (!in_array($reason, ['purchase', 'sale', 'return', 'loss', 'correction'], true)) {
            throw new InvalidArgumentException('Motif de mouvement invalide.');
        }
        $this->product = $product;
        $this->quantity = number_format((float) $quantity, 3, '.', '');
        $this->reason = $reason;
        $this->note = trim((string) $note) !== '' ? mb_substr(trim((string) $note), 0, 2000) : null;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getProduct(): Product { return $this->product; }
    public function getQuantity(): string { return $this->quantity; }
    public function getReason(): string { return $this->reason; }
    public function getNote(): ?string { return $this->note; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
}
