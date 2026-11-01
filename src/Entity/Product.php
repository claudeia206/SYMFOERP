<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'app_product', uniqueConstraints: [new ORM\UniqueConstraint(name: 'uniq_product_sku', columns: ['sku'])])]
#[ORM\Index(name: 'idx_product_active_name', columns: ['active', 'name'])]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $sku;

    #[ORM\Column(length: 180)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 40)]
    private string $unit = 'pièce';

    #[ORM\Column(name: 'purchase_price', type: 'decimal', precision: 12, scale: 2)]
    private string $purchasePrice = '0.00';

    #[ORM\Column(name: 'sale_price', type: 'decimal', precision: 12, scale: 2)]
    private string $salePrice = '0.00';

    #[ORM\Column(name: 'vat_rate', type: 'decimal', precision: 5, scale: 2)]
    private string $vatRate = '20.00';

    #[ORM\Column(name: 'stock_tracked', options: ['default' => true])]
    private bool $stockTracked = true;

    #[ORM\Column(name: 'stock_quantity', type: 'decimal', precision: 12, scale: 3)]
    private string $stockQuantity = '0.000';

    #[ORM\Column(name: 'stock_alert_threshold', type: 'decimal', precision: 12, scale: 3)]
    private string $stockAlertThreshold = '0.000';

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(string $sku, string $name)
    {
        $this->setSku($sku);
        $this->setName($name);
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getSku(): string { return $this->sku; }
    public function getName(): string { return $this->name; }
    public function getDescription(): ?string { return $this->description; }
    public function getUnit(): string { return $this->unit; }
    public function getPurchasePrice(): string { return $this->purchasePrice; }
    public function getSalePrice(): string { return $this->salePrice; }
    public function getVatRate(): string { return $this->vatRate; }
    public function isStockTracked(): bool { return $this->stockTracked; }
    public function getStockQuantity(): string { return $this->stockQuantity; }
    public function getStockAlertThreshold(): string { return $this->stockAlertThreshold; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): self { $this->active = $active; $this->updatedAt = new DateTimeImmutable(); return $this; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function isLowStock(): bool { return $this->stockTracked && (float) $this->stockQuantity <= (float) $this->stockAlertThreshold; }

    public function update(array $data): self
    {
        $this->setSku((string) ($data['sku'] ?? ''));
        $this->setName((string) ($data['name'] ?? ''));
        $this->description = trim((string) ($data['description'] ?? '')) ?: null;
        $this->unit = trim((string) ($data['unit'] ?? ''));
        if ($this->unit === '' || mb_strlen($this->unit) > 40) {
            throw new InvalidArgumentException('L’unité est obligatoire (40 caractères maximum).');
        }
        $this->purchasePrice = $this->money($data['purchase_price'] ?? null, 'Prix d’achat');
        $this->salePrice = $this->money($data['sale_price'] ?? null, 'Prix de vente');
        $vat = $data['vat_rate'] ?? null;
        if (!is_numeric($vat) || (float) $vat < 0 || (float) $vat > 100) {
            throw new InvalidArgumentException('Le taux de TVA doit être compris entre 0 et 100.');
        }
        $this->vatRate = number_format((float) $vat, 2, '.', '');
        $this->stockTracked = isset($data['stock_tracked']) && (string) $data['stock_tracked'] === '1';
        $threshold = $data['stock_alert_threshold'] ?? '0';
        if (!is_numeric($threshold) || (float) $threshold < 0 || (float) $threshold > 999999999.999) {
            throw new InvalidArgumentException('Le seuil de stock doit être un nombre positif ou nul.');
        }
        $this->stockAlertThreshold = number_format((float) $threshold, 3, '.', '');
        $this->active = isset($data['active']) && (string) $data['active'] === '1';
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function setSku(string $sku): self
    {
        $sku = trim($sku);
        if ($sku === '' || mb_strlen($sku) > 64) {
            throw new InvalidArgumentException('La référence SKU est obligatoire (64 caractères maximum).');
        }
        $this->sku = $sku;
        return $this;
    }

    public function setName(string $name): self
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 180) {
            throw new InvalidArgumentException('La désignation est obligatoire (180 caractères maximum).');
        }
        $this->name = $name;
        return $this;
    }

    public function adjustStock(string $quantity): self
    {
        if (!$this->stockTracked || !is_numeric($quantity) || (float) $quantity == 0.0 || abs((float) $quantity) > 999999999.999) {
            throw new InvalidArgumentException('Mouvement de stock invalide.');
        }
        $next = round((float) $this->stockQuantity + (float) $quantity, 3);
        if ($next < 0) {
            throw new InvalidArgumentException('Le stock disponible ne peut pas devenir négatif.');
        }
        $this->stockQuantity = number_format($next, 3, '.', '');
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    private function money(mixed $value, string $label): string
    {
        if (!is_numeric($value) || (float) $value < 0 || (float) $value > 9999999999.99) {
            throw new InvalidArgumentException($label.' doit être un montant positif ou nul.');
        }
        return number_format((float) $value, 2, '.', '');
    }
}
