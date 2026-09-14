<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Type\UuidType;
use App\Repository\SupplierProductMappingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * What a supplier calls one of our products. Learned from received e-Facturi (BT-157 barcode,
 * BT-155 seller item code, item description) and from the product the user picks for a line,
 * so the supplier's next invoice lands on the same product without a new one being created.
 */
#[ORM\Entity(repositoryClass: SupplierProductMappingRepository::class)]
#[ORM\Table(name: 'supplier_product_mapping')]
#[ORM\Index(name: 'idx_spm_supplier_barcode', columns: ['supplier_id', 'barcode'])]
#[ORM\Index(name: 'idx_spm_supplier_code', columns: ['supplier_id', 'supplier_code'])]
#[ORM\Index(name: 'idx_spm_supplier_description', columns: ['supplier_id', 'description_key'])]
class SupplierProductMapping
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Supplier $supplier;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    /** BT-157 StandardItemIdentification (EAN/GTIN) as printed by the supplier. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $barcode = null;

    /** BT-155 SellersItemIdentification: the supplier's own article code. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $supplierCode = null;

    /** Normalised item description (lower-case, single spaces), the weakest key. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $descriptionKey = null;

    /** How many lines were matched through this mapping. */
    #[ORM\Column(type: Types::INTEGER)]
    private int $hits = 0;

    /** Set when the user chose the product by hand (wins over imports). */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $confirmed = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastUsedAt;

    public function __construct(Company $company, Supplier $supplier, Product $product)
    {
        $this->id = Uuid::v4();
        $this->company = $company;
        $this->supplier = $supplier;
        $this->product = $product;
        $this->createdAt = new \DateTimeImmutable();
        $this->lastUsedAt = $this->createdAt;
    }

    public static function normalizeDescription(?string $description): ?string
    {
        $key = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $description)));
        return $key === '' ? null : mb_substr($key, 0, 255);
    }

    public function getId(): ?Uuid { return $this->id; }
    public function getCompany(): Company { return $this->company; }
    public function getSupplier(): Supplier { return $this->supplier; }
    public function getProduct(): Product { return $this->product; }
    public function setProduct(Product $product): static { $this->product = $product; return $this; }
    public function getBarcode(): ?string { return $this->barcode; }
    public function setBarcode(?string $barcode): static { $this->barcode = $barcode; return $this; }
    public function getSupplierCode(): ?string { return $this->supplierCode; }
    public function setSupplierCode(?string $supplierCode): static { $this->supplierCode = $supplierCode; return $this; }
    public function getDescriptionKey(): ?string { return $this->descriptionKey; }
    public function setDescriptionKey(?string $descriptionKey): static { $this->descriptionKey = $descriptionKey; return $this; }
    public function getHits(): int { return $this->hits; }
    public function isConfirmed(): bool { return $this->confirmed; }
    public function setConfirmed(bool $confirmed): static { $this->confirmed = $confirmed; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getLastUsedAt(): \DateTimeImmutable { return $this->lastUsedAt; }

    public function touch(): static
    {
        $this->hits++;
        $this->lastUsedAt = new \DateTimeImmutable();
        return $this;
    }
}
