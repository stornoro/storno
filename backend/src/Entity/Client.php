<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Entity\Traits\SoftDeletableTrait;
use App\Repository\ClientRepository;
use Doctrine\ORM\Mapping as ORM;
use App\Doctrine\Type\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: ClientRepository::class)]
#[ORM\Index(name: 'idx_client_company_cui', columns: ['company_id', 'cui', 'deleted_at'])]
#[ORM\Index(name: 'idx_client_company_name', columns: ['company_id', 'name', 'deleted_at'])]
class Client
{
    use AuditableTrait;
    use SoftDeletableTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['client:list', 'client:detail', 'invoice:list', 'invoice:detail', 'proforma:detail', 'recurring_invoice:detail', 'delivery_note:list', 'delivery_note:detail', 'receipt:list', 'receipt:detail'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(inversedBy: 'clients')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Company $company = null;

    #[ORM\Column(length: 20)]
    #[Groups(['client:list', 'client:detail', 'invoice:detail', 'proforma:detail', 'recurring_invoice:detail', 'delivery_note:detail', 'receipt:detail'])]
    private string $type = 'company'; // company or individual

    #[ORM\Column(length: 255)]
    #[Groups(['client:list', 'client:detail', 'invoice:list', 'invoice:detail', 'proforma:list', 'proforma:detail', 'recurring_invoice:list', 'recurring_invoice:detail', 'delivery_note:list', 'delivery_note:detail', 'receipt:list', 'receipt:detail'])]
    private ?string $name = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['client:list', 'client:detail', 'invoice:list', 'invoice:detail', 'proforma:list', 'proforma:detail', 'recurring_invoice:list', 'recurring_invoice:detail', 'delivery_note:list', 'delivery_note:detail', 'receipt:list', 'receipt:detail'])]
    private ?string $cui = null;

    #[ORM\Column(length: 13, nullable: true)]
    #[Groups(['client:list', 'client:detail', 'invoice:detail', 'proforma:detail', 'recurring_invoice:detail', 'delivery_note:detail', 'receipt:detail'])]
    private ?string $cnp = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['client:list', 'client:detail'])]
    private ?string $vatCode = null;

    #[ORM\Column]
    #[Groups(['client:list', 'client:detail', 'invoice:list', 'invoice:detail'])]
    private bool $isVatPayer = false;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['client:detail', 'delivery_note:detail', 'receipt:detail'])]
    private ?string $registrationNumber = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['client:list', 'client:detail', 'delivery_note:detail', 'receipt:detail'])]
    private ?string $address = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['client:list', 'client:detail', 'invoice:detail', 'proforma:detail', 'recurring_invoice:detail', 'delivery_note:detail', 'receipt:detail'])]
    private ?string $city = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['client:detail', 'delivery_note:detail', 'receipt:detail'])]
    private ?string $county = null;

    #[ORM\Column(length: 2)]
    #[Groups(['client:list', 'client:detail', 'invoice:detail', 'proforma:detail', 'recurring_invoice:detail', 'delivery_note:detail', 'receipt:detail'])]
    private string $country = 'RO';

    #[ORM\Column(length: 10, nullable: true)]
    #[Groups(['client:detail', 'delivery_note:detail', 'receipt:detail'])]
    private ?string $postalCode = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['client:list', 'client:detail', 'invoice:detail', 'proforma:detail', 'recurring_invoice:detail', 'delivery_note:detail', 'receipt:detail'])]
    private ?string $email = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['client:detail', 'delivery_note:detail', 'receipt:detail'])]
    private ?string $phone = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['client:detail'])]
    private ?string $bankName = null;

    #[ORM\Column(length: 34, nullable: true)]
    #[Groups(['client:detail'])]
    private ?string $bankAccount = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['client:detail'])]
    private ?int $defaultPaymentTermDays = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['client:list', 'client:detail'])]
    private ?string $contactPerson = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['client:list', 'client:detail'])]
    private ?string $clientCode = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['client:detail'])]
    private ?string $notes = null;

    #[ORM\Column(length: 20)]
    #[Groups(['client:detail'])]
    private string $source = 'anaf_sync';

    #[ORM\Column(nullable: true)]
    #[Groups(['client:detail'])]
    private ?\DateTimeImmutable $lastSyncedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['client:detail'])]
    private ?array $einvoiceIdentifiers = null;

    #[ORM\Column(type: 'boolean', nullable: true)]
    #[Groups(['client:list', 'client:detail'])]
    private ?bool $viesValid = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['client:detail'])]
    private ?\DateTimeImmutable $viesValidatedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['client:detail'])]
    private ?string $viesName = null;

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

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

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

    public function getCui(): ?string
    {
        return $this->cui;
    }

    public function setCui(?string $cui): static
    {
        $this->cui = $cui;

        return $this;
    }

    public function getCnp(): ?string
    {
        return $this->cnp;
    }

    public function setCnp(?string $cnp): static
    {
        $this->cnp = $cnp;

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

    public function getDefaultPaymentTermDays(): ?int
    {
        return $this->defaultPaymentTermDays;
    }

    public function setDefaultPaymentTermDays(?int $defaultPaymentTermDays): static
    {
        $this->defaultPaymentTermDays = $defaultPaymentTermDays;

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

    public function getContactPerson(): ?string
    {
        return $this->contactPerson;
    }

    public function setContactPerson(?string $contactPerson): static
    {
        $this->contactPerson = $contactPerson;

        return $this;
    }

    public function getClientCode(): ?string
    {
        return $this->clientCode;
    }

    public function setClientCode(?string $clientCode): static
    {
        $this->clientCode = $clientCode;

        return $this;
    }

    public function getEinvoiceIdentifiers(): ?array
    {
        return $this->einvoiceIdentifiers;
    }

    public function setEinvoiceIdentifiers(?array $einvoiceIdentifiers): static
    {
        $this->einvoiceIdentifiers = $einvoiceIdentifiers;

        return $this;
    }

    public function getEinvoiceIdentifier(string $provider): ?array
    {
        return $this->einvoiceIdentifiers[$provider] ?? null;
    }

    public function isViesValid(): ?bool
    {
        return $this->viesValid;
    }

    public function setViesValid(?bool $viesValid): static
    {
        $this->viesValid = $viesValid;

        return $this;
    }

    public function getViesValidatedAt(): ?\DateTimeImmutable
    {
        return $this->viesValidatedAt;
    }

    public function setViesValidatedAt(?\DateTimeImmutable $viesValidatedAt): static
    {
        $this->viesValidatedAt = $viesValidatedAt;

        return $this;
    }

    public function getViesName(): ?string
    {
        return $this->viesName;
    }

    public function setViesName(?string $viesName): static
    {
        $this->viesName = $viesName;

        return $this;
    }
}
