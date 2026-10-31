<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductRepository;
use App\Service\StockQuantity;
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

    #[ORM\Column(name: 'stock_quantity', type: 'decimal', precision: 12, scale: 3, options: ['default' => '0.000'])]
    private string $stockQuantity = '0.000';

    #[ORM\Column(name: 'stock_alert_threshold', type: 'decimal', precision: 12, scale: 3, options: ['default' => '0.000'])]
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
    public function isLowStock(): bool { return $this->stockTracked && StockQuantity::toMilliunits($this->stockQuantity) <= StockQuantity::toMilliunits($this->stockAlertThreshold); }

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
        $vatCents = $this->decimalToCents($data['vat_rate'] ?? null, 'Taux de TVA', 3);
        if ($vatCents > 10000) {
            throw new InvalidArgumentException('Le taux de TVA doit être compris entre 0 et 100.');
        }
        $this->vatRate = $this->formatCents($vatCents);
        $this->stockTracked = isset($data['stock_tracked']) && (string) $data['stock_tracked'] === '1';
        $threshold = $data['stock_alert_threshold'] ?? '0';
        $this->stockAlertThreshold = StockQuantity::format(StockQuantity::toMilliunits($threshold));
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
        if (!$this->stockTracked) {
            throw new InvalidArgumentException('Le suivi de stock est désactivé pour ce produit.');
        }
        $delta = StockQuantity::toMilliunits($quantity, true);
        if ($delta === 0) {
            throw new InvalidArgumentException('Un mouvement de stock doit être non nul.');
        }
        $next = StockQuantity::toMilliunits($this->stockQuantity) + $delta;
        if ($next < 0) {
            throw new InvalidArgumentException('Le stock disponible ne peut pas devenir négatif.');
        }
        if ($next > 999999999999) {
            throw new InvalidArgumentException('Le stock dépasse la capacité maximale autorisée.');
        }
        $this->stockQuantity = StockQuantity::format($next);
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    private function money(mixed $value, string $label): string
    {
        return $this->formatCents($this->decimalToCents($value, $label, 10));
    }

    private function decimalToCents(mixed $value, string $label, int $maxWholeDigits): int
    {
        if (!is_string($value) && !is_int($value)) {
            throw new InvalidArgumentException($label.' doit être un nombre décimal valide.');
        }

        $raw = trim((string) $value);
        $pattern = '/^(?:0|[1-9]\\\\d{0,'.($maxWholeDigits - 1).' })(?:\\\\.\\\\d{1,2})?$/D';
        $pattern = str_replace(' ', '', $pattern);
        if (!preg_match($pattern, $raw)) {
            throw new InvalidArgumentException($label.' doit être positif ou nul, avec deux décimales maximum.');
        }

        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function formatCents(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
