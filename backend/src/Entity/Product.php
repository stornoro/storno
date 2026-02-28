<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Entity\Traits\SoftDeletableTrait;
use App\Repository\ProductRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Doctrine\Type\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Index(name: 'idx_product_company_name', columns: ['company_id', 'name', 'deleted_at'])]
#[ORM\Index(name: 'idx_product_company_code', columns: ['company_id', 'code', 'deleted_at'])]
class Product
{
    use AuditableTrait;
    use SoftDeletableTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['product:list', 'product:detail'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(inversedBy: 'products')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Company $company = null;

    #[ORM\Column(length: 255)]
    #[Groups(['product:list', 'product:detail'])]
    private ?string $name = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['product:list', 'product:detail'])]
    private ?string $code = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['product:detail'])]
    private ?string $description = null;

    #[ORM\Column(length: 20)]
    #[Groups(['product:list', 'product:detail'])]
    private string $unitOfMeasure = 'buc';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Groups(['product:list', 'product:detail'])]
    private string $defaultPrice = '0.00';

    #[ORM\Column(length: 3)]
    #[Groups(['product:list', 'product:detail'])]
    private string $currency = 'RON';

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    #[Groups(['product:list', 'product:detail'])]
    private string $vatRate = '21.00';

    #[ORM\Column(length: 10)]
    #[Groups(['product:detail'])]
    private string $vatCategoryCode = 'S'; // S=standard, Z=zero, E=exempt, AE=reverse charge

    #[ORM\Column]
    #[Groups(['product:list', 'product:detail'])]
    private bool $isService = false;

    #[ORM\Column]
    #[Groups(['product:list'])]
    private bool $isActive = true;

    #[ORM\Column(name: '`usage`', length: 20)]
    #[Groups(['product:list', 'product:detail'])]
    private string $usage = 'both'; // sales, purchases, both, internal

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['product:list', 'product:detail'])]
    private ?string $ncCode = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['product:list', 'product:detail'])]
    private ?string $cpvCode = null;

    #[ORM\Column(length: 20)]
    #[Groups(['product:detail'])]
    private string $source = 'anaf_sync';

    #[ORM\Column(nullable: true)]
    #[Groups(['product:detail'])]
    private ?\DateTimeImmutable $lastSyncedAt = null;

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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getUnitOfMeasure(): string
    {
        return $this->unitOfMeasure;
    }

    public function setUnitOfMeasure(string $unitOfMeasure): static
    {
        $this->unitOfMeasure = $unitOfMeasure;

        return $this;
    }

    public function getDefaultPrice(): string
    {
        return $this->defaultPrice;
    }

    public function setDefaultPrice(string $defaultPrice): static
    {
        $this->defaultPrice = $defaultPrice;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = $currency;

        return $this;
    }

    public function getVatRate(): string
    {
        return $this->vatRate;
    }

    public function setVatRate(string $vatRate): static
    {
        $this->vatRate = $vatRate;

        return $this;
    }

    public function getVatCategoryCode(): string
    {
        return $this->vatCategoryCode;
    }

    public function setVatCategoryCode(string $vatCategoryCode): static
    {
        $this->vatCategoryCode = $vatCategoryCode;

        return $this;
    }

    public function isService(): bool
    {
        return $this->isService;
    }

    public function setIsService(bool $isService): static
    {
        $this->isService = $isService;

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

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getLastSyncedAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncedAt;
    }

    public function setLastSyncedAt(?\DateTimeImmutable $lastSyncedAt): static
    {
        $this->lastSyncedAt = $lastSyncedAt;

        return $this;
    }

    public function getUsage(): string
    {
        return $this->usage;
    }

    public function setUsage(string $usage): static
    {
        $this->usage = $usage;

        return $this;
    }

    public function getNcCode(): ?string
    {
        return $this->ncCode;
    }

    public function setNcCode(?string $ncCode): static
    {
        $this->ncCode = $ncCode;

        return $this;
    }

    public function getCpvCode(): ?string
    {
        return $this->cpvCode;
    }

    public function setCpvCode(?string $cpvCode): static
    {
        $this->cpvCode = $cpvCode;

        return $this;
    }

}
