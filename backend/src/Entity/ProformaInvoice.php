<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Entity\Traits\SoftDeletableTrait;
use App\Enum\ProformaStatus;
use App\Repository\ProformaInvoiceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Doctrine\Type\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ProformaInvoiceRepository::class)]
#[ORM\Index(name: 'idx_proforma_company_status_created', columns: ['company_id', 'status', 'deleted_at', 'created_at'])]
class ProformaInvoice
{
    use AuditableTrait;
    use SoftDeletableTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['proforma:list', 'proforma:detail'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Company $company = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['proforma:list', 'proforma:detail'])]
    private ?Client $client = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['proforma:detail'])]
    private ?DocumentSeries $documentSeries = null;

    #[ORM\Column(length: 255)]
    #[Groups(['proforma:list', 'proforma:detail'])]
    private ?string $number = null;

    #[ORM\Column(length: 20, enumType: ProformaStatus::class)]
    #[Groups(['proforma:list', 'proforma:detail'])]
    private ProformaStatus $status = ProformaStatus::DRAFT;

    #[ORM\Column(length: 3)]
    #[Groups(['proforma:list', 'proforma:detail'])]
    private string $currency = 'RON';

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Groups(['proforma:list', 'proforma:detail'])]
    private ?\DateTimeInterface $issueDate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['proforma:list', 'proforma:detail'])]
    private ?\DateTimeInterface $dueDate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['proforma:list', 'proforma:detail'])]
    private ?\DateTimeInterface $validUntil = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Groups(['proforma:list', 'proforma:detail'])]
    private string $subtotal = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Groups(['proforma:list', 'proforma:detail'])]
    private string $vatTotal = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Groups(['proforma:list', 'proforma:detail'])]
    private string $total = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Groups(['proforma:detail'])]
    private string $discount = '0.00';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['proforma:detail'])]
    private ?string $notes = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['proforma:detail'])]
    private ?string $paymentTerms = null;

    #[ORM\Column(length: 40, nullable: true)]
    #[Groups(['proforma:detail'])]
    private ?string $invoiceTypeCode = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['proforma:detail'])]
    private ?string $deliveryLocation = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['proforma:detail'])]
    private ?string $projectReference = null;

    // UBL BT-13: Purchase order reference
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['proforma:detail'])]
    private ?string $orderNumber = null;

    // UBL BT-12: Contract reference
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['proforma:detail'])]
    private ?string $contractNumber = null;

    // Issuer info (Intocmit de)
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['proforma:detail'])]
    private ?string $issuerName = null;

    #[ORM\Column(length: 13, nullable: true)]
    #[Groups(['proforma:detail'])]
    private ?string $issuerId = null;

    // Legal mentions (printed on document)
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['proforma:detail'])]
    private ?string $mentions = null;

    // Internal note (not visible to client)
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['proforma:detail'])]
    private ?string $internalNote = null;

    // Sales agent
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['proforma:detail'])]
    private ?string $salesAgent = null;

    // Document language for PDF generation
    #[ORM\Column(length: 10)]
    #[Groups(['proforma:detail'])]
    private string $language = 'ro';

    // Exchange rate
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    #[Groups(['proforma:detail'])]
    private ?string $exchangeRate = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['proforma:detail'])]
    private ?Invoice $convertedInvoice = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['proforma:detail'])]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['proforma:detail'])]
    private ?\DateTimeImmutable $acceptedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['proforma:detail'])]
    private ?\DateTimeImmutable $rejectedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['proforma:detail'])]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['proforma:list', 'proforma:detail'])]
    private ?\DateTimeImmutable $expiredAt = null;

    #[ORM\OneToMany(mappedBy: 'proformaInvoice', targetEntity: ProformaInvoiceLine::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Groups(['proforma:detail'])]
    private Collection $lines;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->lines = new ArrayCollection();
        $this->issueDate = new \DateTime();
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

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(?Client $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getDocumentSeries(): ?DocumentSeries
    {
        return $this->documentSeries;
    }

    public function setDocumentSeries(?DocumentSeries $documentSeries): static
    {
        $this->documentSeries = $documentSeries;

        return $this;
    }

    public function getNumber(): ?string
    {
        return $this->number;
    }

    public function setNumber(string $number): static
    {
        $this->number = $number;

        return $this;
    }

    public function getStatus(): ProformaStatus
    {
        return $this->status;
    }

    public function setStatus(ProformaStatus $status): static
    {
        $this->status = $status;

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

    public function getIssueDate(): ?\DateTimeInterface
    {
        return $this->issueDate;
    }

    public function setIssueDate(\DateTimeInterface $issueDate): static
    {
        $this->issueDate = $issueDate;

        return $this;
    }

    public function getDueDate(): ?\DateTimeInterface
    {
        return $this->dueDate;
    }

    public function setDueDate(?\DateTimeInterface $dueDate): static
    {
        $this->dueDate = $dueDate;

        return $this;
    }

    public function getValidUntil(): ?\DateTimeInterface
    {
        return $this->validUntil;
    }

    public function setValidUntil(?\DateTimeInterface $validUntil): static
    {
        $this->validUntil = $validUntil;

        return $this;
    }

    public function getSubtotal(): string
    {
        return $this->subtotal;
    }

    public function setSubtotal(string $subtotal): static
    {
        $this->subtotal = $subtotal;

        return $this;
    }

    public function getVatTotal(): string
    {
        return $this->vatTotal;
    }

    public function setVatTotal(string $vatTotal): static
    {
        $this->vatTotal = $vatTotal;

        return $this;
    }

    public function getTotal(): string
    {
        return $this->total;
    }

    public function setTotal(string $total): static
    {
        $this->total = $total;

        return $this;
    }

    public function getDiscount(): string
    {
        return $this->discount;
    }

    public function setDiscount(string $discount): static
    {
        $this->discount = $discount;

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

    public function getPaymentTerms(): ?string
    {
        return $this->paymentTerms;
    }

    public function setPaymentTerms(?string $paymentTerms): static
    {
        $this->paymentTerms = $paymentTerms;

        return $this;
    }

    public function getInvoiceTypeCode(): ?string
    {
        return $this->invoiceTypeCode;
    }

    public function setInvoiceTypeCode(?string $invoiceTypeCode): static
    {
        $this->invoiceTypeCode = $invoiceTypeCode;

        return $this;
    }

    public function getDeliveryLocation(): ?string
    {
        return $this->deliveryLocation;
    }

    public function setDeliveryLocation(?string $deliveryLocation): static
    {
        $this->deliveryLocation = $deliveryLocation;

        return $this;
    }

    public function getProjectReference(): ?string
    {
        return $this->projectReference;
    }

    public function setProjectReference(?string $projectReference): static
    {
        $this->projectReference = $projectReference;

        return $this;
    }

    public function getConvertedInvoice(): ?Invoice
    {
        return $this->convertedInvoice;
    }

    public function setConvertedInvoice(?Invoice $convertedInvoice): static
    {
        $this->convertedInvoice = $convertedInvoice;

        return $this;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTimeImmutable $sentAt): static
    {
        $this->sentAt = $sentAt;

        return $this;
    }

    public function getAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function setAcceptedAt(?\DateTimeImmutable $acceptedAt): static
    {
        $this->acceptedAt = $acceptedAt;

        return $this;
    }

    public function getRejectedAt(): ?\DateTimeImmutable
    {
        return $this->rejectedAt;
    }

    public function setRejectedAt(?\DateTimeImmutable $rejectedAt): static
    {
        $this->rejectedAt = $rejectedAt;

        return $this;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function setCancelledAt(?\DateTimeImmutable $cancelledAt): static
    {
        $this->cancelledAt = $cancelledAt;

        return $this;
    }

    public function getExpiredAt(): ?\DateTimeImmutable
    {
        return $this->expiredAt;
    }

    public function setExpiredAt(?\DateTimeImmutable $expiredAt): static
    {
        $this->expiredAt = $expiredAt;

        return $this;
    }

    /**
     * @return Collection<int, ProformaInvoiceLine>
     */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(ProformaInvoiceLine $line): static
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setProformaInvoice($this);
        }

        return $this;
    }

    public function removeLine(ProformaInvoiceLine $line): static
    {
        if ($this->lines->removeElement($line)) {
            if ($line->getProformaInvoice() === $this) {
                $line->setProformaInvoice(null);
            }
        }

        return $this;
    }

    public function clearLines(): static
    {
        $this->lines->clear();

        return $this;
    }

    public function getOrderNumber(): ?string
    {
        return $this->orderNumber;
    }

    public function setOrderNumber(?string $orderNumber): static
    {
        $this->orderNumber = $orderNumber;

        return $this;
    }

    public function getContractNumber(): ?string
    {
        return $this->contractNumber;
    }

    public function setContractNumber(?string $contractNumber): static
    {
        $this->contractNumber = $contractNumber;

        return $this;
    }

    public function getIssuerName(): ?string
    {
        return $this->issuerName;
    }

    public function setIssuerName(?string $issuerName): static
    {
        $this->issuerName = $issuerName;

        return $this;
    }

    public function getIssuerId(): ?string
    {
        return $this->issuerId;
    }

    public function setIssuerId(?string $issuerId): static
    {
        $this->issuerId = $issuerId;

        return $this;
    }

    public function getMentions(): ?string
    {
        return $this->mentions;
    }

    public function setMentions(?string $mentions): static
    {
        $this->mentions = $mentions;

        return $this;
    }

    public function getInternalNote(): ?string
    {
        return $this->internalNote;
    }

    public function setInternalNote(?string $internalNote): static
    {
        $this->internalNote = $internalNote;

        return $this;
    }

    public function getSalesAgent(): ?string
    {
        return $this->salesAgent;
    }

    public function setSalesAgent(?string $salesAgent): static
    {
        $this->salesAgent = $salesAgent;

        return $this;
    }

    public function getExchangeRate(): ?string
    {
        return $this->exchangeRate;
    }

    public function setExchangeRate(?string $exchangeRate): static
    {
        $this->exchangeRate = $exchangeRate;

        return $this;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function setLanguage(string $language): static
    {
        $this->language = $language;

        return $this;
    }

    public function isEditable(): bool
    {
        return $this->status === ProformaStatus::DRAFT;
    }

    public function isDeletable(): bool
    {
        return in_array($this->status, [ProformaStatus::DRAFT, ProformaStatus::CANCELLED], true);
    }

    #[Groups(['proforma:list'])]
    public function getClientName(): ?string
    {
        return $this->client?->getName();
    }
}
