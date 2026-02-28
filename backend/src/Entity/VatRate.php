<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Entity\Traits\SoftDeletableTrait;
use App\Repository\VatRateRepository;
use Doctrine\ORM\Mapping as ORM;
use App\Doctrine\Type\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: VatRateRepository::class)]
#[ORM\Index(name: 'idx_vatrate_company_deleted', columns: ['company_id', 'deleted_at'])]
class VatRate
{
    use AuditableTrait;
    use SoftDeletableTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['vat_rate:list', 'vat_rate:detail'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Company $company = null;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2)]
    #[Groups(['vat_rate:list', 'vat_rate:detail'])]
    private ?string $rate = null;

    #[ORM\Column(length: 100)]
    #[Groups(['vat_rate:list', 'vat_rate:detail'])]
    private ?string $label = null;

    #[ORM\Column(length: 10)]
    #[Groups(['vat_rate:list', 'vat_rate:detail'])]
    private string $categoryCode = 'S';

    #[ORM\Column]
    #[Groups(['vat_rate:list', 'vat_rate:detail'])]
    private bool $isDefault = false;

    #[ORM\Column]
    #[Groups(['vat_rate:list', 'vat_rate:detail'])]
    private bool $isActive = true;

    #[ORM\Column]
    #[Groups(['vat_rate:list', 'vat_rate:detail'])]
    private int $position = 0;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): ?Uuid
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

    public function getRate(): ?string
    {
        return $this->rate;
    }

    public function setRate(string $rate): static
    {
        $this->rate = $rate;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getCategoryCode(): string
    {
        return $this->categoryCode;
    }

    public function setCategoryCode(string $categoryCode): static
    {
        $this->categoryCode = $categoryCode;

        return $this;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(bool $isDefault): static
    {
        $this->isDefault = $isDefault;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }
}
