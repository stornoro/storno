<?php

namespace App\Entity;

use App\Doctrine\Type\UuidType;
use App\Entity\ProformaInvoice;
use App\Repository\BorderouTransactionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: BorderouTransactionRepository::class)]
#[ORM\Index(name: 'idx_borderou_tx_company_status', columns: ['company_id', 'status'])]
#[ORM\Index(name: 'idx_borderou_tx_import_job', columns: ['import_job_id'])]
#[ORM\Index(name: 'idx_borderou_tx_company_bankref', columns: ['company_id', 'bank_reference'])]
class BorderouTransaction
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['borderou:list', 'borderou:detail'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Company $company = null;

    #[ORM\ManyToOne(targetEntity: ImportJob::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ImportJob $importJob = null;

    // ── Parsed data from file ──

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Groups(['borderou:list', 'borderou:detail'])]
    private ?\DateTimeInterface $transactionDate = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['borderou:list', 'borderou:detail'])]
    private ?string $clientName = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['borderou:detail'])]
    private ?string $clientCif = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['borderou:list', 'borderou:detail'])]
    private ?string $explanation = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Groups(['borderou:list', 'borderou:detail'])]
    private string $amount = '0.00';

    #[ORM\Column(length: 3, options: ['default' => 'RON'])]
    #[Groups(['borderou:list', 'borderou:detail'])]
    private string $currency = 'RON';

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['borderou:list', 'borderou:detail'])]
    private ?string $awbNumber = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['borderou:detail'])]
    private ?string $bankReference = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['borderou:list', 'borderou:detail'])]
    private ?string $documentType = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['borderou:list', 'borderou:detail'])]
    private ?string $documentNumber = null;

    // ── Source info ──

    #[ORM\Column(length: 30)]
    #[Groups(['borderou:list', 'borderou:detail'])]
    private string $sourceType = 'borderou';

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['borderou:list', 'borderou:detail'])]
    private ?string $sourceProvider = null;

    // ── Matching result ──

    #[ORM\Column(length: 20)]
    #[Groups(['borderou:list', 'borderou:detail'])]
    private string $matchConfidence = 'no_match';

    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Invoice $matchedInvoice = null;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Client $matchedClient = null;

    #[ORM\ManyToOne(targetEntity: ProformaInvoice::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?ProformaInvoice $matchedProformaInvoice = null;

    // ── Status ──

    #[ORM\Column(length: 20)]
    #[Groups(['borderou:list', 'borderou:detail'])]
    private string $status = 'unsaved';

    #[ORM\ManyToOne(targetEntity: Payment::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Payment $createdPayment = null;

    // ── Raw data for reference ──

    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['borderou:detail'])]
    private ?array $rawData = null;

    #[ORM\Column]
    #[Groups(['borderou:list', 'borderou:detail'])]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
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

    public function getImportJob(): ?ImportJob
    {
        return $this->importJob;
    }

    public function setImportJob(?ImportJob $importJob): static
    {
        $this->importJob = $importJob;

        return $this;
    }

    public function getTransactionDate(): ?\DateTimeInterface
    {
        return $this->transactionDate;
    }

    public function setTransactionDate(\DateTimeInterface $transactionDate): static
    {
        $this->transactionDate = $transactionDate;

        return $this;
    }

    public function getClientName(): ?string
    {
        return $this->clientName;
    }

    public function setClientName(?string $clientName): static
    {
        $this->clientName = $clientName;

        return $this;
    }

    public function getClientCif(): ?string
    {
        return $this->clientCif;
    }

    public function setClientCif(?string $clientCif): static
    {
        $this->clientCif = $clientCif;

        return $this;
    }

    public function getExplanation(): ?string
    {
        return $this->explanation;
    }

    public function setExplanation(?string $explanation): static
    {
        $this->explanation = $explanation;

        return $this;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
    {
        $this->amount = $amount;

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

    public function getAwbNumber(): ?string
    {
        return $this->awbNumber;
    }

    public function setAwbNumber(?string $awbNumber): static
    {
        $this->awbNumber = $awbNumber;

        return $this;
    }

    public function getBankReference(): ?string
    {
        return $this->bankReference;
    }

    public function setBankReference(?string $bankReference): static
    {
        $this->bankReference = $bankReference;

        return $this;
    }

    public function getDocumentType(): ?string
    {
        return $this->documentType;
    }

    public function setDocumentType(?string $documentType): static
    {
        $this->documentType = $documentType;

        return $this;
    }

    public function getDocumentNumber(): ?string
    {
        return $this->documentNumber;
    }

    public function setDocumentNumber(?string $documentNumber): static
    {
        $this->documentNumber = $documentNumber;

        return $this;
    }

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    public function setSourceType(string $sourceType): static
    {
        $this->sourceType = $sourceType;

        return $this;
    }

    public function getSourceProvider(): ?string
    {
        return $this->sourceProvider;
    }

    public function setSourceProvider(?string $sourceProvider): static
    {
        $this->sourceProvider = $sourceProvider;

        return $this;
    }

    public function getMatchConfidence(): string
    {
        return $this->matchConfidence;
    }

    public function setMatchConfidence(string $matchConfidence): static
    {
        $this->matchConfidence = $matchConfidence;

        return $this;
    }

    public function getMatchedInvoice(): ?Invoice
    {
        return $this->matchedInvoice;
    }

    public function setMatchedInvoice(?Invoice $matchedInvoice): static
    {
        $this->matchedInvoice = $matchedInvoice;

        return $this;
    }

    public function getMatchedClient(): ?Client
    {
        return $this->matchedClient;
    }

    public function setMatchedClient(?Client $matchedClient): static
    {
        $this->matchedClient = $matchedClient;

        return $this;
    }

    public function getMatchedProformaInvoice(): ?ProformaInvoice
    {
        return $this->matchedProformaInvoice;
    }

    public function setMatchedProformaInvoice(?ProformaInvoice $matchedProformaInvoice): static
    {
        $this->matchedProformaInvoice = $matchedProformaInvoice;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedPayment(): ?Payment
    {
        return $this->createdPayment;
    }

    public function setCreatedPayment(?Payment $createdPayment): static
    {
        $this->createdPayment = $createdPayment;

        return $this;
    }

    public function getRawData(): ?array
    {
        return $this->rawData;
    }

    public function setRawData(?array $rawData): static
    {
        $this->rawData = $rawData;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    // ── Serialization helpers for list group ──

    #[Groups(['borderou:list'])]
    #[SerializedName('matchedInvoiceNumber')]
    public function getMatchedInvoiceNumber(): ?string
    {
        return $this->matchedInvoice?->getNumber();
    }

    #[Groups(['borderou:list'])]
    #[SerializedName('matchedInvoiceId')]
    public function getMatchedInvoiceId(): ?string
    {
        return $this->matchedInvoice?->getId()?->toRfc4122();
    }

    #[Groups(['borderou:list'])]
    #[SerializedName('matchedProformaInvoiceNumber')]
    public function getMatchedProformaInvoiceNumber(): ?string
    {
        return $this->matchedProformaInvoice?->getNumber();
    }

    #[Groups(['borderou:list'])]
    #[SerializedName('matchedProformaInvoiceId')]
    public function getMatchedProformaInvoiceId(): ?string
    {
        return $this->matchedProformaInvoice?->getId()?->toRfc4122();
    }

    #[Groups(['borderou:list'])]
    #[SerializedName('matchedClientName')]
    public function getMatchedClientName(): ?string
    {
        return $this->matchedClient?->getName();
    }

    #[Groups(['borderou:list'])]
    #[SerializedName('matchedClientId')]
    public function getMatchedClientId(): ?string
    {
        return $this->matchedClient?->getId()?->toRfc4122();
    }

    #[Groups(['borderou:list'])]
    #[SerializedName('importJobId')]
    public function getImportJobId(): ?string
    {
        return $this->importJob?->getId()?->toRfc4122();
    }
}
