<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Entity\Traits\SoftDeletableTrait;
use App\Enum\DeliveryNoteStatus;
use App\Repository\DeliveryNoteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Doctrine\Type\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DeliveryNoteRepository::class)]
#[ORM\Index(name: 'idx_delivery_note_company_status_created', columns: ['company_id', 'status', 'deleted_at', 'created_at'])]
class DeliveryNote
{
    use AuditableTrait;
    use SoftDeletableTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['delivery_note:list', 'delivery_note:detail'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Company $company = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['delivery_note:list', 'delivery_note:detail'])]
    private ?Client $client = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?DocumentSeries $documentSeries = null;

    #[ORM\Column(length: 255)]
    #[Groups(['delivery_note:list', 'delivery_note:detail'])]
    private ?string $number = null;

    #[ORM\Column(length: 20, enumType: DeliveryNoteStatus::class)]
    #[Groups(['delivery_note:list', 'delivery_note:detail'])]
    private DeliveryNoteStatus $status = DeliveryNoteStatus::DRAFT;

    #[ORM\Column(length: 3)]
    #[Groups(['delivery_note:list', 'delivery_note:detail'])]
    private string $currency = 'RON';

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Groups(['delivery_note:list', 'delivery_note:detail'])]
    private ?\DateTimeInterface $issueDate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['delivery_note:list', 'delivery_note:detail'])]
    private ?\DateTimeInterface $dueDate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Groups(['delivery_note:list', 'delivery_note:detail'])]
    private string $subtotal = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Groups(['delivery_note:list', 'delivery_note:detail'])]
    private string $vatTotal = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Groups(['delivery_note:list', 'delivery_note:detail'])]
    private string $total = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Groups(['delivery_note:detail'])]
    private string $discount = '0.00';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?string $notes = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?string $mentions = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?string $internalNote = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?string $deliveryLocation = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?string $projectReference = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?string $issuerName = null;

    #[ORM\Column(length: 13, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?string $issuerId = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?string $salesAgent = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?string $deputyName = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?string $deputyIdentityCard = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?string $deputyAuto = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?string $exchangeRate = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?Invoice $convertedInvoice = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?\DateTimeImmutable $issuedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?\DateTimeImmutable $cancelledAt = null;

    // e-Transport operation
    #[ORM\Column(nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?int $etransportOperationType = null;

    #[ORM\Column(length: 1, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?string $etransportPostIncident = null;

    // Transport data
    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?string $etransportVehicleNumber = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?string $etransportTrailer1 = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?string $etransportTrailer2 = null;

    #[ORM\Column(length: 2, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?string $etransportTransporterCountry = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?string $etransportTransporterCode = null;

    #[ORM\Column(length: 200, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?string $etransportTransporterName = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?\DateTimeInterface $etransportTransportDate = null;

    // Route start location
    #[ORM\Column(nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?int $etransportStartCounty = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?string $etransportStartLocality = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?string $etransportStartStreet = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?string $etransportStartNumber = null;

    #[ORM\Column(length: 200, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?string $etransportStartOtherInfo = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?string $etransportStartPostalCode = null;

    // Route end location
    #[ORM\Column(nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?int $etransportEndCounty = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?string $etransportEndLocality = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?string $etransportEndStreet = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?string $etransportEndNumber = null;

    #[ORM\Column(length: 200, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?string $etransportEndOtherInfo = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?string $etransportEndPostalCode = null;

    // ANAF tracking
    #[ORM\Column(length: 16, nullable: true)]
    #[Groups(['delivery_note:list', 'delivery_note:detail'])]
    private ?string $etransportUit = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $etransportUploadId = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['delivery_note:list', 'delivery_note:detail'])]
    private ?string $etransportStatus = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?string $etransportErrorMessage = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $etransportXmlPath = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?\DateTimeImmutable $etransportSubmittedAt = null;

    #[ORM\OneToMany(mappedBy: 'deliveryNote', targetEntity: DeliveryNoteLine::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Groups(['delivery_note:detail'])]
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

    public function getStatus(): DeliveryNoteStatus
    {
        return $this->status;
    }

    public function setStatus(DeliveryNoteStatus $status): static
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

    public function getSalesAgent(): ?string
    {
        return $this->salesAgent;
    }

    public function setSalesAgent(?string $salesAgent): static
    {
        $this->salesAgent = $salesAgent;

        return $this;
    }

    public function getDeputyName(): ?string
    {
        return $this->deputyName;
    }

    public function setDeputyName(?string $deputyName): static
    {
        $this->deputyName = $deputyName;

        return $this;
    }

    public function getDeputyIdentityCard(): ?string
    {
        return $this->deputyIdentityCard;
    }

    public function setDeputyIdentityCard(?string $deputyIdentityCard): static
    {
        $this->deputyIdentityCard = $deputyIdentityCard;

        return $this;
    }

    public function getDeputyAuto(): ?string
    {
        return $this->deputyAuto;
    }

    public function setDeputyAuto(?string $deputyAuto): static
    {
        $this->deputyAuto = $deputyAuto;

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

    public function getConvertedInvoice(): ?Invoice
    {
        return $this->convertedInvoice;
    }

    public function setConvertedInvoice(?Invoice $convertedInvoice): static
    {
        $this->convertedInvoice = $convertedInvoice;

        return $this;
    }

    public function getIssuedAt(): ?\DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function setIssuedAt(?\DateTimeImmutable $issuedAt): static
    {
        $this->issuedAt = $issuedAt;

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

    /**
     * @return Collection<int, DeliveryNoteLine>
     */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(DeliveryNoteLine $line): static
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setDeliveryNote($this);
        }

        return $this;
    }

    public function removeLine(DeliveryNoteLine $line): static
    {
        if ($this->lines->removeElement($line)) {
            if ($line->getDeliveryNote() === $this) {
                $line->setDeliveryNote(null);
            }
        }

        return $this;
    }

    public function clearLines(): static
    {
        $this->lines->clear();

        return $this;
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [DeliveryNoteStatus::DRAFT, DeliveryNoteStatus::ISSUED], true);
    }

    public function isDeletable(): bool
    {
        return in_array($this->status, [DeliveryNoteStatus::DRAFT, DeliveryNoteStatus::CANCELLED], true);
    }

    #[Groups(['delivery_note:list'])]
    public function getClientName(): ?string
    {
        return $this->client?->getName();
    }

    // e-Transport getters/setters

    public function getEtransportOperationType(): ?int
    {
        return $this->etransportOperationType;
    }

    public function setEtransportOperationType(?int $etransportOperationType): static
    {
        $this->etransportOperationType = $etransportOperationType;
        return $this;
    }

    public function getEtransportPostIncident(): ?string
    {
        return $this->etransportPostIncident;
    }

    public function setEtransportPostIncident(?string $etransportPostIncident): static
    {
        $this->etransportPostIncident = $etransportPostIncident;
        return $this;
    }

    public function getEtransportVehicleNumber(): ?string
    {
        return $this->etransportVehicleNumber;
    }

    public function setEtransportVehicleNumber(?string $etransportVehicleNumber): static
    {
        $this->etransportVehicleNumber = $etransportVehicleNumber;
        return $this;
    }

    public function getEtransportTrailer1(): ?string
    {
        return $this->etransportTrailer1;
    }

    public function setEtransportTrailer1(?string $etransportTrailer1): static
    {
        $this->etransportTrailer1 = $etransportTrailer1;
        return $this;
    }

    public function getEtransportTrailer2(): ?string
    {
        return $this->etransportTrailer2;
    }

    public function setEtransportTrailer2(?string $etransportTrailer2): static
    {
        $this->etransportTrailer2 = $etransportTrailer2;
        return $this;
    }

    public function getEtransportTransporterCountry(): ?string
    {
        return $this->etransportTransporterCountry;
    }

    public function setEtransportTransporterCountry(?string $etransportTransporterCountry): static
    {
        $this->etransportTransporterCountry = $etransportTransporterCountry;
        return $this;
    }

    public function getEtransportTransporterCode(): ?string
    {
        return $this->etransportTransporterCode;
    }

    public function setEtransportTransporterCode(?string $etransportTransporterCode): static
    {
        $this->etransportTransporterCode = $etransportTransporterCode;
        return $this;
    }

    public function getEtransportTransporterName(): ?string
    {
        return $this->etransportTransporterName;
    }

    public function setEtransportTransporterName(?string $etransportTransporterName): static
    {
        $this->etransportTransporterName = $etransportTransporterName;
        return $this;
    }

    public function getEtransportTransportDate(): ?\DateTimeInterface
    {
        return $this->etransportTransportDate;
    }

    public function setEtransportTransportDate(?\DateTimeInterface $etransportTransportDate): static
    {
        $this->etransportTransportDate = $etransportTransportDate;
        return $this;
    }

    public function getEtransportStartCounty(): ?int
    {
        return $this->etransportStartCounty;
    }

    public function setEtransportStartCounty(?int $etransportStartCounty): static
    {
        $this->etransportStartCounty = $etransportStartCounty;
        return $this;
    }

    public function getEtransportStartLocality(): ?string
    {
        return $this->etransportStartLocality;
    }

    public function setEtransportStartLocality(?string $etransportStartLocality): static
    {
        $this->etransportStartLocality = $etransportStartLocality;
        return $this;
    }

    public function getEtransportStartStreet(): ?string
    {
        return $this->etransportStartStreet;
    }

    public function setEtransportStartStreet(?string $etransportStartStreet): static
    {
        $this->etransportStartStreet = $etransportStartStreet;
        return $this;
    }

    public function getEtransportStartNumber(): ?string
    {
        return $this->etransportStartNumber;
    }

    public function setEtransportStartNumber(?string $etransportStartNumber): static
    {
        $this->etransportStartNumber = $etransportStartNumber;
        return $this;
    }

    public function getEtransportStartOtherInfo(): ?string
    {
        return $this->etransportStartOtherInfo;
    }

    public function setEtransportStartOtherInfo(?string $etransportStartOtherInfo): static
    {
        $this->etransportStartOtherInfo = $etransportStartOtherInfo;
        return $this;
    }

    public function getEtransportStartPostalCode(): ?string
    {
        return $this->etransportStartPostalCode;
    }

    public function setEtransportStartPostalCode(?string $etransportStartPostalCode): static
    {
        $this->etransportStartPostalCode = $etransportStartPostalCode;
        return $this;
    }

    public function getEtransportEndCounty(): ?int
    {
        return $this->etransportEndCounty;
    }

    public function setEtransportEndCounty(?int $etransportEndCounty): static
    {
        $this->etransportEndCounty = $etransportEndCounty;
        return $this;
    }

    public function getEtransportEndLocality(): ?string
    {
        return $this->etransportEndLocality;
    }

    public function setEtransportEndLocality(?string $etransportEndLocality): static
    {
        $this->etransportEndLocality = $etransportEndLocality;
        return $this;
    }

    public function getEtransportEndStreet(): ?string
    {
        return $this->etransportEndStreet;
    }

    public function setEtransportEndStreet(?string $etransportEndStreet): static
    {
        $this->etransportEndStreet = $etransportEndStreet;
        return $this;
    }

    public function getEtransportEndNumber(): ?string
    {
        return $this->etransportEndNumber;
    }

    public function setEtransportEndNumber(?string $etransportEndNumber): static
    {
        $this->etransportEndNumber = $etransportEndNumber;
        return $this;
    }

    public function getEtransportEndOtherInfo(): ?string
    {
        return $this->etransportEndOtherInfo;
    }

    public function setEtransportEndOtherInfo(?string $etransportEndOtherInfo): static
    {
        $this->etransportEndOtherInfo = $etransportEndOtherInfo;
        return $this;
    }

    public function getEtransportEndPostalCode(): ?string
    {
        return $this->etransportEndPostalCode;
    }

    public function setEtransportEndPostalCode(?string $etransportEndPostalCode): static
    {
        $this->etransportEndPostalCode = $etransportEndPostalCode;
        return $this;
    }

    public function getEtransportUit(): ?string
    {
        return $this->etransportUit;
    }

    public function setEtransportUit(?string $etransportUit): static
    {
        $this->etransportUit = $etransportUit;
        return $this;
    }

    public function getEtransportUploadId(): ?string
    {
        return $this->etransportUploadId;
    }

    public function setEtransportUploadId(?string $etransportUploadId): static
    {
        $this->etransportUploadId = $etransportUploadId;
        return $this;
    }

    public function getEtransportStatus(): ?string
    {
        return $this->etransportStatus;
    }

    public function setEtransportStatus(?string $etransportStatus): static
    {
        $this->etransportStatus = $etransportStatus;
        return $this;
    }

    public function getEtransportErrorMessage(): ?string
    {
        return $this->etransportErrorMessage;
    }

    public function setEtransportErrorMessage(?string $etransportErrorMessage): static
    {
        $this->etransportErrorMessage = $etransportErrorMessage;
        return $this;
    }

    public function getEtransportXmlPath(): ?string
    {
        return $this->etransportXmlPath;
    }

    public function setEtransportXmlPath(?string $etransportXmlPath): static
    {
        $this->etransportXmlPath = $etransportXmlPath;
        return $this;
    }

    public function getEtransportSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->etransportSubmittedAt;
    }

    public function setEtransportSubmittedAt(?\DateTimeImmutable $etransportSubmittedAt): static
    {
        $this->etransportSubmittedAt = $etransportSubmittedAt;
        return $this;
    }
}
