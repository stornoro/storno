<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Entity\Traits\SoftDeletableTrait;
use App\Repository\SupplierRepository;
use Doctrine\ORM\Mapping as ORM;
use App\Doctrine\Type\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: SupplierRepository::class)]
#[ORM\Index(name: 'idx_supplier_company_cif', columns: ['company_id', 'cif', 'deleted_at'])]
#[ORM\Index(name: 'idx_supplier_company_name', columns: ['company_id', 'name', 'deleted_at'])]
class Supplier
{
    use AuditableTrait;
    use SoftDeletableTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['supplier:list', 'supplier:detail', 'invoice:list', 'invoice:detail'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Company $company = null;

    #[ORM\Column(length: 255)]
    #[Groups(['supplier:list', 'supplier:detail', 'invoice:list', 'invoice:detail'])]
    private ?string $name = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['supplier:list', 'supplier:detail', 'invoice:list'])]
    private ?string $cif = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['supplier:list', 'supplier:detail'])]
    private ?string $vatCode = null;

    #[ORM\Column]
    #[Groups(['supplier:list', 'supplier:detail'])]
    private bool $isVatPayer = false;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['supplier:detail'])]
    private ?string $registrationNumber = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['supplier:list', 'supplier:detail'])]
    private ?string $address = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['supplier:list', 'supplier:detail'])]
    private ?string $city = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['supplier:detail'])]
    private ?string $county = null;

    #[ORM\Column(length: 2)]
    #[Groups(['supplier:detail'])]
    private string $country = 'RO';

    #[ORM\Column(length: 10, nullable: true)]
    #[Groups(['supplier:detail'])]
    private ?string $postalCode = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['supplier:list', 'supplier:detail'])]
    private ?string $email = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['supplier:detail'])]
    private ?string $phone = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['supplier:detail'])]
    private ?string $bankName = null;

    #[ORM\Column(length: 34, nullable: true)]
    #[Groups(['supplier:detail'])]
    private ?string $bankAccount = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['supplier:detail'])]
    private ?string $notes = null;

    #[ORM\Column(length: 20)]
    #[Groups(['supplier:detail'])]
    private string $source = 'anaf_sync';

    #[ORM\Column(nullable: true)]
    #[Groups(['supplier:detail'])]
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

    public function getCif(): ?string
    {
        return $this->cif;
    }

    public function setCif(?string $cif): static
    {
        $this->cif = $cif;

        return $this;
    }

    public function getVatCode(): ?string
    {
        return $this->vatCode;
    }

    public function setVatCode(?string $vatCode): static
    {
        $this->vatCode = $vatCode;

        return $this;
    }

    public function isVatPayer(): bool
    {
        return $this->isVatPayer;
    }

    public function setIsVatPayer(bool $isVatPayer): static
    {
        $this->isVatPayer = $isVatPayer;

        return $this;
    }

    public function getRegistrationNumber(): ?string
    {
        return $this->registrationNumber;
    }

    public function setRegistrationNumber(?string $registrationNumber): static
    {
        $this->registrationNumber = $registrationNumber;

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): static
    {
        $this->city = $city;

        return $this;
    }

    public function getCounty(): ?string
    {
        return $this->county;
    }

    public function setCounty(?string $county): static
    {
        $this->county = $county;

        return $this;
    }

    public function getCountry(): string
    {
        return $this->country;
    }

    public function setCountry(string $country): static
    {
        $this->country = $country;

        return $this;
    }

    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    public function setPostalCode(?string $postalCode): static
    {
        $this->postalCode = $postalCode;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getBankName(): ?string
    {
        return $this->bankName;
    }

    public function setBankName(?string $bankName): static
    {
        $this->bankName = $bankName;

        return $this;
    }

    public function getBankAccount(): ?string
    {
        return $this->bankAccount;
    }

    public function setBankAccount(?string $bankAccount): static
    {
        $this->bankAccount = $bankAccount;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

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
}
