<?php

namespace App\Entity;

use App\Repository\ThirdPartyRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity(repositoryClass: ThirdPartyRepository::class)]
#[ORM\Table(name: 'app_third_party')]
#[ORM\Index(name: 'idx_third_party_category_name', columns: ['category', 'name'])]
class ThirdParty
{
    public const CATEGORY_CUSTOMER = 'customer';
    public const CATEGORY_PROSPECT = 'prospect';
    public const CATEGORY_SUPPLIER = 'supplier';

    private const CATEGORIES = [
        self::CATEGORY_CUSTOMER,
        self::CATEGORY_PROSPECT,
        self::CATEGORY_SUPPLIER,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $name;

    #[ORM\Column(length: 20)]
    private string $category;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(name: 'postal_code', length: 20, nullable: true)]
    private ?string $postalCode = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $country = null;

    #[ORM\Column(name: 'created_at')]
    private DateTimeImmutable $createdAt;

    public function __construct(string $name, string $category = self::CATEGORY_CUSTOMER)
    {
        $this->setName($name);
        $this->setCategory($category);
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 180) {
            throw new InvalidArgumentException('A third party name must contain between 1 and 180 characters.');
        }

        $this->name = $name;

        return $this;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): self
    {
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new InvalidArgumentException('Unsupported third party category.');
        }

        $this->category = $category;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $email = $email !== null ? trim($email) : '';
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 180)) {
            throw new InvalidArgumentException('A valid email address of at most 180 characters is required.');
        }

        $this->email = $email !== '' ? $email : null;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        $phone = $phone !== null ? trim($phone) : '';
        if (mb_strlen($phone) > 40) {
            throw new InvalidArgumentException('A phone number cannot exceed 40 characters.');
        }

        $this->phone = $phone !== '' ? $phone : null;

        return $this;
    }

    public function getAddress(): ?string { return $this->address; }
    public function getPostalCode(): ?string { return $this->postalCode; }
    public function getCity(): ?string { return $this->city; }
    public function getCountry(): ?string { return $this->country; }

    public function setAddress(?string $value): self { $this->address = trim((string) $value) ?: null; return $this; }
    public function setPostalCode(?string $value): self { $this->postalCode = trim((string) $value) ?: null; return $this; }
    public function setCity(?string $value): self { $this->city = trim((string) $value) ?: null; return $this; }
    public function setCountry(?string $value): self { $this->country = trim((string) $value) ?: null; return $this; }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
