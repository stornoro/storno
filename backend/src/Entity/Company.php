<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Entity\Traits\SoftDeletableTrait;
use App\Model\Anaf\CompanyInfo;
use App\Model\Invoice\InvoiceAddress;
use App\Repository\CompanyRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use App\Doctrine\Type\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CompanyRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_company_org_cif', columns: ['organization_id', 'cif'])]
class Company
{
    use AuditableTrait;
    use SoftDeletableTrait;

    public const ALL_MODULES = [
        'delivery_notes',
        'receipts',
        'proforma_invoices',
        'recurring_invoices',
        'reports',
        'efactura',
        'spv_messages',
    ];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(inversedBy: 'companies')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Organization $organization = null;

    #[ORM\Column(length: 255)]
    #[Groups(['company', 'invoice'])]
    private ?string $name = null;

    #[ORM\Column(length: 255)]
    #[Groups(['company', 'invoice'])]
    private int $cif;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['company', 'invoice'])]
    private ?string $registrationNumber = null;

    #[ORM\Column]
    #[Groups(['company', 'invoice'])]
    private bool $vatPayer = false;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['company', 'invoice'])]
    private ?string $vatCode = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['company', 'invoice'])]
    private ?string $address = null;

    #[ORM\Column(length: 255)]
    #[Groups(['company', 'invoice'])]
    private ?string $city = null;

    #[ORM\Column(length: 255)]
    #[Groups(['company', 'invoice'])]
    private ?string $state = null;

    #[ORM\Column(length: 255)]
    #[Groups(['company', 'invoice'])]
    private ?string $country = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['company', 'invoice'])]
    private ?string $sector = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['company'])]
    private ?string $phone = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['company'])]
    private ?string $email = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['company'])]
    private ?string $website = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['company'])]
    private ?string $capitalSocial = null;

    #[ORM\Column]
    #[Groups(['company'])]
    private bool $vatOnCollection = false;

    #[ORM\Column]
    #[Groups(['company'])]
    private bool $oss = false;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['company'])]
    private ?string $vatIn = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['company'])]
    private ?string $eoriCode = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['company'])]
    private ?string $representative = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['company'])]
    private ?string $representativeRole = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['company'])]
    private ?string $bankName = null;

    #[ORM\Column(length: 34, nullable: true)]
    #[Groups(['company'])]
    private ?string $bankAccount = null;

    #[ORM\Column(length: 11, nullable: true)]
    #[Groups(['company'])]
    private ?string $bankBic = null;

    #[ORM\Column(length: 3)]
    #[Groups(['company'])]
    private string $defaultCurrency = 'RON';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoPath = null;

    #[ORM\Column]
    #[Groups(['company'])]
    private bool $archiveEnabled = false;

    #[ORM\Column(nullable: true)]
    #[Groups(['company'])]
    private ?int $archiveRetentionYears = 5;

    #[ORM\Column]
    #[Groups(['company'])]
    private bool $syncEnabled = false;

    #[ORM\Column(nullable: true)]
    #[Groups(['company'])]
    private ?\DateTimeImmutable $lastSyncedAt = null;

    #[ORM\Column]
    #[Groups(['company'])]
    private int $syncDaysBack = 60;

    #[ORM\Column(nullable: true)]
    #[Groups(['company'])]
    private ?int $efacturaDelayHours = null;

    #[ORM\Column]
    #[Groups(['company'])]
    private bool $isReadOnly = false;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $exportSettings = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['company'])]
    private ?array $enabledModules = null;

    #[ORM\OneToMany(mappedBy: 'company', targetEntity: Invoice::class, cascade: ['persist'])]
    private Collection $invoices;

    #[ORM\OneToMany(mappedBy: 'company', targetEntity: Client::class, cascade: ['persist'])]
    private Collection $clients;

    #[ORM\OneToMany(mappedBy: 'company', targetEntity: Product::class, cascade: ['persist'])]
    private Collection $products;


    public function __construct(InvoiceAddress $invoiceAddress = null, CompanyInfo $companyInfo = null)
    {
        $this->id = Uuid::v7();
        $this->invoices = new ArrayCollection();
        $this->clients = new ArrayCollection();
        $this->products = new ArrayCollection();

        if ($invoiceAddress) {
            $this->setName($invoiceAddress->getName());
            $this->setAddress($invoiceAddress->getAddress());
            $this->setCity($invoiceAddress->getCity());
            $this->setCountry($invoiceAddress->getCountry());
            $this->setState($invoiceAddress->getState());
            $this->setCif($invoiceAddress->getVat());
        }
        if ($companyInfo) {
            $this->setCif($companyInfo->getCif());
            $this->setName($companyInfo->getName());
            $this->setAddress($companyInfo->getAddress());
            $this->setCity($companyInfo->getCity());
            $this->setState($companyInfo->getState());
            $this->setCountry($companyInfo->getCountry());
            $this->setSector($companyInfo->getSector());
            $this->setVatPayer($companyInfo->isVatPayer());
            $this->setVatCode($companyInfo->getVatCode());
            $this->setRegistrationNumber($companyInfo->getRegistrationNumber());
            if ($companyInfo->getPhone()) {
                $this->setPhone($companyInfo->getPhone());
            }
        }
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getOrganization(): ?Organization
    {
        return $this->organization;
    }

    public function setOrganization(?Organization $organization): static
    {
        $this->organization = $organization;

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

    public function getCif(): int
    {
        return $this->cif;
    }

    public function setCif(int $cif): static
    {
        $this->cif = $cif;

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

    public function isVatPayer(): bool
    {
        return $this->vatPayer;
    }

    public function setVatPayer(bool $vatPayer): static
    {
        $this->vatPayer = $vatPayer;

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

    public function setCity(string $city): static
    {
        $this->city = $city;

        return $this;
    }

    public function getState(): ?string
    {
        return $this->state;
    }

    public function setState(string $state): static
    {
        $this->state = $state;

        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(string $country): static
    {
        $this->country = $country;

        return $this;
    }

    public function getSector(): ?string
    {
        return $this->sector;
    }

    public function setSector(?string $sector): static
    {
        $this->sector = $sector;

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

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): static
    {
        $this->website = $website;

        return $this;
    }

    public function getCapitalSocial(): ?string
    {
        return $this->capitalSocial;
    }

    public function setCapitalSocial(?string $capitalSocial): static
    {
        $this->capitalSocial = $capitalSocial;

        return $this;
    }

    public function isVatOnCollection(): bool
    {
        return $this->vatOnCollection;
    }

    public function setVatOnCollection(bool $vatOnCollection): static
    {
        $this->vatOnCollection = $vatOnCollection;

        return $this;
    }

    public function isOss(): bool
    {
        return $this->oss;
    }

    public function setOss(bool $oss): static
    {
        $this->oss = $oss;

        return $this;
    }

    public function getVatIn(): ?string
    {
        return $this->vatIn;
    }

    public function setVatIn(?string $vatIn): static
    {
        $this->vatIn = $vatIn;

        return $this;
    }

    public function getEoriCode(): ?string
    {
        return $this->eoriCode;
    }

    public function setEoriCode(?string $eoriCode): static
    {
        $this->eoriCode = $eoriCode;

        return $this;
    }

    public function getRepresentative(): ?string
    {
        return $this->representative;
    }

    public function setRepresentative(?string $representative): static
    {
        $this->representative = $representative;

        return $this;
    }

    public function getRepresentativeRole(): ?string
    {
        return $this->representativeRole;
    }

    public function setRepresentativeRole(?string $representativeRole): static
    {
        $this->representativeRole = $representativeRole;

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

    public function getBankBic(): ?string
    {
        return $this->bankBic;
    }

    public function setBankBic(?string $bankBic): static
    {
        $this->bankBic = $bankBic;

        return $this;
    }

    public function getDefaultCurrency(): string
    {
        return $this->defaultCurrency;
    }

    public function setDefaultCurrency(string $defaultCurrency): static
    {
        $this->defaultCurrency = $defaultCurrency;

        return $this;
    }

    public function getLogoPath(): ?string
    {
        return $this->logoPath;
    }

    public function setLogoPath(?string $logoPath): static
    {
        $this->logoPath = $logoPath;

        return $this;
    }

    /**
     * @return Collection<int, Invoice>
     */
    public function getInvoices(): Collection
    {
        return $this->invoices;
    }

    public function addInvoice(Invoice $invoice): static
    {
        if (!$this->invoices->contains($invoice)) {
            $this->invoices->add($invoice);
            $invoice->setCompany($this);
        }

        return $this;
    }

    public function removeInvoice(Invoice $invoice): static
    {
        if ($this->invoices->removeElement($invoice)) {
            if ($invoice->getCompany() === $this) {
                $invoice->setCompany(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Client>
     */
    public function getClients(): Collection
    {
        return $this->clients;
    }

    /**
     * @return Collection<int, Product>
     */
    public function getProducts(): Collection
    {
        return $this->products;
    }

    public function isSyncEnabled(): bool
    {
        return $this->syncEnabled;
    }

    public function setSyncEnabled(bool $syncEnabled): static
    {
        $this->syncEnabled = $syncEnabled;

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

    public function getSyncDaysBack(): int
    {
        return $this->syncDaysBack;
    }

    public function setSyncDaysBack(int $syncDaysBack): static
    {
        $this->syncDaysBack = $syncDaysBack;

        return $this;
    }

    public function isArchiveEnabled(): bool
    {
        return $this->archiveEnabled;
    }

    public function setArchiveEnabled(bool $archiveEnabled): static
    {
        $this->archiveEnabled = $archiveEnabled;

        return $this;
    }

    public function getArchiveRetentionYears(): ?int
    {
        return $this->archiveRetentionYears;
    }

    public function setArchiveRetentionYears(?int $archiveRetentionYears): static
    {
        $this->archiveRetentionYears = $archiveRetentionYears;

        return $this;
    }

    public function getEfacturaDelayHours(): ?int
    {
        return $this->efacturaDelayHours;
    }

    public function setEfacturaDelayHours(?int $efacturaDelayHours): static
    {
        $this->efacturaDelayHours = $efacturaDelayHours;

        return $this;
    }

    public function isReadOnly(): bool
    {
        return $this->isReadOnly;
    }

    public function setReadOnly(bool $isReadOnly): static
    {
        $this->isReadOnly = $isReadOnly;

        return $this;
    }

    public function getEnabledModules(): ?array
    {
        return $this->enabledModules;
    }

    public function setEnabledModules(?array $enabledModules): static
    {
        $this->enabledModules = $enabledModules;

        return $this;
    }

    public function isModuleEnabled(string $module): bool
    {
        if ($this->enabledModules === null) {
            return true;
        }

        return in_array($module, $this->enabledModules, true);
    }

    public function getExportSettings(): ?array
    {
        return $this->exportSettings;
    }

    public function setExportSettings(?array $exportSettings): static
    {
        $this->exportSettings = $exportSettings;

        return $this;
    }

    public function getExportSettingsWithDefaults(): array
    {
        $defaults = [
            'saga' => [
                'accountCash' => '5311',
                'accountBank' => '5121',
                'accountCard' => '5125',
                'accountClients' => '4111',
                'accountSuppliers' => '4011',
            ],
            'winmentor' => [
                'bankName' => '',
                'bankNumber' => '',
                'bankLocality' => '',
            ],
            'ciel' => [],
        ];

        if (!$this->exportSettings) {
            return $defaults;
        }

        return array_replace_recursive($defaults, $this->exportSettings);
    }

    public static function createFromJson(InvoiceAddress $invoice): self
    {
        return new self($invoice);
    }

    public static function createFromAnaf(CompanyInfo $company): self
    {
        return new self(companyInfo: $company);
    }
}
