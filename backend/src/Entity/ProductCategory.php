<?php

namespace App\Entity;

use App\Doctrine\Type\UuidType;
use App\Repository\ProductCategoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Cashier-defined grouping for products on the POS grid (e.g. "Cafele",
 * "Sandwich-uri"). Each category may carry a hex colour swatch used as the
 * fallback Product card colour when a product doesn't have its own colour.
 */
#[ORM\Entity(repositoryClass: ProductCategoryRepository::class)]
#[ORM\Index(name: 'idx_product_category_company_sort', columns: ['company_id', 'sort_order'])]
class ProductCategory
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['product_category:list', 'product_category:detail', 'product:list', 'product:detail'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Company $company = null;

    #[ORM\Column(length: 100)]
    #[Groups(['product_category:list', 'product_category:detail', 'product:list', 'product:detail'])]
    private string $name = '';

    /** Optional hex colour swatch for the POS chip + product card fallback. */
    #[ORM\Column(length: 7, nullable: true)]
    #[Groups(['product_category:list', 'product_category:detail', 'product:list', 'product:detail'])]
    private ?string $color = null;

    /** Sort order for chip strip and management UI; smaller = earlier. */
    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['product_category:list', 'product_category:detail'])]
    private int $sortOrder = 0;

    #[ORM\Column]
    #[Groups(['product_category:detail'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    #[Groups(['product_category:detail'])]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    public function setCompany(?Company $company): static
    {
        $this->company = $company;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): static
    {
        $this->color = $color;
        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touchUpdatedAt(): static
    {
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }
}
