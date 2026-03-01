<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Entity\Traits\SoftDeletableTrait;
use App\Enum\DocumentStatus;
use App\Enum\DocumentType;
use App\Enum\InvoiceDirection;
use App\Repository\InvoiceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Doctrine\Type\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: InvoiceRepository::class)]
#[ORM\Index(name: 'idx_invoice_company_status_created', columns: ['company_id', 'status', 'deleted_at', 'created_at'])]
#[ORM\Index(name: 'idx_invoice_company_issue_date', columns: ['company_id', 'issue_date', 'deleted_at'])]
#[ORM\Index(name: 'idx_invoice_company_synced', columns: ['company_id', 'synced_at'])]
#[ORM\Index(name: 'idx_invoice_company_direction', columns: ['company_id', 'direction', 'deleted_at'])]
#[ORM\Index(name: 'idx_invoice_anaf_status', columns: ['status', 'anaf_upload_id'])]
#[ORM\Index(name: 'idx_invoice_scheduled_send', columns: ['status', 'scheduled_send_at', 'direction', 'deleted_at'])]
#[ORM\Index(name: 'idx_invoice_scheduled_email', columns: ['scheduled_email_at', 'status', 'deleted_at'])]
#[ORM\Index(name: 'idx_invoice_company_sender_cif', columns: ['company_id', 'sender_cif'])]
#[ORM\Index(name: 'idx_invoice_company_receiver_cif', columns: ['company_id', 'receiver_cif'])]
class Invoice
{
    use AuditableTrait;
    use SoftDeletableTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['invoice:list', 'invoice:detail'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(inversedBy: 'invoices', cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['invoice:list'])]
    private ?Company $company = null;

    #[ORM\ManyToOne(cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['invoice:list', 'invoice:detail'])]
    private ?Client $client = null;


    #[ORM\Column(length: 20, enumType: DocumentType::class)]
    #[Groups(['invoice:list', 'invoice:detail'])]
    private DocumentType $documentType = DocumentType::INVOICE;

    #[ORM\Column(length: 20, enumType: DocumentStatus::class)]
    #[Groups(['invoice:list', 'invoice:detail'])]
    private DocumentStatus $status = DocumentStatus::DRAFT;

    #[ORM\Column(length: 20, nullable: true, enumType: InvoiceDirection::class)]
    #[Groups(['invoice:list', 'invoice:detail'])]
    private ?InvoiceDirection $direction = null;

    #[ORM\Column(length: 255, nullable: true, unique: true)]
    #[Groups(['invoice:detail'])]
    private ?string $anafMessageId = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['invoice:list', 'invoice:detail'])]
    private ?string $senderCif = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['invoice:list', 'invoice:detail'])]
    private ?string $receiverCif = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['invoice:list', 'invoice:detail'])]
    private ?string $senderName = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['invoice:list', 'invoice:detail'])]
    private ?string $receiverName = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $signatureContent = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?bool $signatureValid = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?\DateTimeImmutable $syncedAt = null;

    #[ORM\Column(length: 255)]
    #[Groups(['invoice:list', 'invoice:detail'])]
    private ?string $number = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Groups(['invoice:list', 'invoice:detail'])]
    private string $subtotal = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Groups(['invoice:list', 'invoice:detail'])]
    private string $vatTotal = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Groups(['invoice:list', 'invoice:detail'])]
    private string $total = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Groups(['invoice:detail'])]
    private string $discount = '0.00';

    #[ORM\Column(length: 3)]
    #[Groups(['invoice:list', 'invoice:detail'])]
    private string $currency = 'RON';

    #[ORM\Column(length: 40, nullable: true)]
    #[Groups(['invoice:list', 'invoice:detail'])]
    private ?string $invoiceTypeCode = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Groups(['invoice:list', 'invoice:detail'])]
    private ?\DateTimeInterface $issueDate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['invoice:list', 'invoice:detail'])]
    private ?\DateTimeInterface $dueDate = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?string $notes = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?string $paymentTerms = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?string $exchangeRate = null;

    #[ORM\Column(length: 10)]
    #[Groups(['invoice:detail'])]
    private string $language = 'ro';

    // ANAF e-Factura fields
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?string $anafUploadId = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?string $anafDownloadId = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?string $anafStatus = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $anafErrorMessage = null;

    // File paths
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $xmlPath = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $pdfPath = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $signaturePath = null;

    // Duplicate detection
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['invoice:list', 'invoice:detail'])]
    private bool $isDuplicate = false;

    // Late submission detection (uploaded to ANAF > 5 days after issue)
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['invoice:list', 'invoice:detail'])]
    private bool $isLateSubmission = false;

    // Payment tracking
    #[ORM\Column(nullable: true)]
    #[Groups(['invoice:list', 'invoice:detail'])]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['invoice:list', 'invoice:detail'])]
    private ?string $paymentMethod = null;

    // Cancellation tracking
    #[ORM\Column(nullable: true)]
    #[Groups(['invoice:list', 'invoice:detail'])]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?string $cancellationReason = null;

    // Scheduled ANAF submission
    #[ORM\Column(nullable: true)]
    #[Groups(['invoice:list', 'invoice:detail'])]
    private ?\DateTimeImmutable $scheduledSendAt = null;

    // Scheduled auto-email (from recurring invoices)
    #[ORM\Column(nullable: true)]
    #[Groups(['invoice:list', 'invoice:detail'])]
    private ?\DateTimeImmutable $scheduledEmailAt = null;

    // UBL BT-71: Delivery location
    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?string $deliveryLocation = null;

    // UBL BT-11: Project reference
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?string $projectReference = null;

    // Self-referencing for refund/conversion
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?self $parentDocument = null;

    #[ORM\OneToMany(mappedBy: 'invoice', targetEntity: InvoiceLine::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Groups(['invoice:detail'])]
    private Collection $lines;

    #[ORM\OneToMany(mappedBy: 'invoice', targetEntity: DocumentEvent::class, cascade: ['persist'])]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $events;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['invoice:list', 'invoice:detail'])]
    private ?Supplier $supplier = null;

    #[ORM\OneToMany(mappedBy: 'invoice', targetEntity: InvoiceAttachment::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['invoice:detail'])]
    private Collection $attachments;

    #[ORM\OneToMany(mappedBy: 'invoice', targetEntity: Payment::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['paymentDate' => 'DESC'])]
    #[Groups(['invoice:detail'])]
    private Collection $payments;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['invoice:list', 'invoice:detail'])]
    private string $amountPaid = '0.00';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?DocumentSeries $documentSeries = null;

    // UBL BT-13: Purchase order reference
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?string $orderNumber = null;

    // UBL BT-12: Contract reference
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?string $contractNumber = null;

    // Issuer info (Intocmit de)
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?string $issuerName = null;

    #[ORM\Column(length: 13, nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?string $issuerId = null;

    // Legal mentions (printed on document)
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?string $mentions = null;

    // Internal note (not visible to client)
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?string $internalNote = null;

    // Sales agent
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?string $salesAgent = null;

    // Transport/delegate info
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?string $deputyName = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?string $deputyIdentityCard = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?string $deputyAuto = null;

    // Idempotency key for duplicate prevention
    #[ORM\Column(length: 255, nullable: true, unique: true)]
    private ?string $idempotencyKey = null;

    // Options
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['invoice:detail'])]
    private bool $tvaLaIncasare = false;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['invoice:detail'])]
    private bool $platitorTva = false;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['invoice:detail'])]
    private bool $plataOnline = false;

    // Client balance
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['invoice:detail'])]
    private bool $showClientBalance = false;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?string $clientBalanceExisting = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?string $clientBalanceOverdue = null;

    // e-Factura BT fields
    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?\DateTimeInterface $taxPointDate = null;

    #[ORM\Column(length: 10, nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?string $taxPointDateCode = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?string $buyerReference = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?string $receivingAdviceReference = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?string $despatchAdviceReference = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?string $tenderOrLotReference = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?string $invoicedObjectIdentifier = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?string $buyerAccountingReference = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?string $businessProcessType = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?string $payeeName = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?string $payeeIdentifier = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?string $payeeLegalRegistrationIdentifier = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['invoice:detail'])]
    private bool $penaltyEnabled = false;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?string $penaltyPercentPerDay = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?int $penaltyGraceDays = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->lines = new ArrayCollection();
        $this->events = new ArrayCollection();
        $this->attachments = new ArrayCollection();
        $this->payments = new ArrayCollection();
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

    public function getDocumentType(): DocumentType
    {
        return $this->documentType;
    }

    public function setDocumentType(DocumentType $documentType): static
    {
        $this->documentType = $documentType;

        return $this;
    }

    public function getStatus(): DocumentStatus
    {
        return $this->status;
    }

    public function setStatus(DocumentStatus $status): static
    {
        $this->status = $status;

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

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = $currency;

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

    public function getAnafUploadId(): ?string
    {
        return $this->anafUploadId;
    }

    public function setAnafUploadId(?string $anafUploadId): static
    {
        $this->anafUploadId = $anafUploadId;

        return $this;
    }

    public function getAnafDownloadId(): ?string
    {
        return $this->anafDownloadId;
    }

    public function setAnafDownloadId(?string $anafDownloadId): static
    {
        $this->anafDownloadId = $anafDownloadId;

        return $this;
    }

    public function getAnafStatus(): ?string
    {
        return $this->anafStatus;
    }

    public function setAnafStatus(?string $anafStatus): static
    {
        $this->anafStatus = $anafStatus;

        return $this;
    }

    public function getAnafErrorMessage(): ?string
    {
        return $this->anafErrorMessage;
    }

    public function setAnafErrorMessage(?string $anafErrorMessage): static
    {
        $this->anafErrorMessage = $anafErrorMessage;

        return $this;
    }

    public function getXmlPath(): ?string
    {
        return $this->xmlPath;
    }

    public function setXmlPath(?string $xmlPath): static
    {
        $this->xmlPath = $xmlPath;

        return $this;
    }

    public function getPdfPath(): ?string
    {
        return $this->pdfPath;
    }

    public function setPdfPath(?string $pdfPath): static
    {
        $this->pdfPath = $pdfPath;

        return $this;
    }

    public function getSignaturePath(): ?string
    {
        return $this->signaturePath;
    }

    public function setSignaturePath(?string $signaturePath): static
    {
        $this->signaturePath = $signaturePath;

        return $this;
    }

    public function getParentDocument(): ?self
    {
        return $this->parentDocument;
    }

    public function setParentDocument(?self $parentDocument): static
    {
        $this->parentDocument = $parentDocument;

        return $this;
    }

    #[Groups(['invoice:list', 'invoice:detail'])]
    public function getParentDocumentId(): ?string
    {
        return $this->parentDocument?->getId()?->toRfc4122();
    }

    #[Groups(['invoice:detail'])]
    public function getParentDocumentNumber(): ?string
    {
        return $this->parentDocument?->getNumber();
    }

    #[Groups(['invoice:detail'])]
    public function getParentDocumentDate(): ?string
    {
        return $this->parentDocument?->getIssueDate()?->format('Y-m-d');
    }

    /**
     * @return Collection<int, InvoiceLine>
     */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(InvoiceLine $line): static
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setInvoice($this);
        }

        return $this;
    }

    public function removeLine(InvoiceLine $line): static
    {
        if ($this->lines->removeElement($line)) {
            if ($line->getInvoice() === $this) {
                $line->setInvoice(null);
            }
        }

        return $this;
    }

    public function clearLines(): static
    {
        $this->lines->clear();

        return $this;
    }

    /**
     * @return Collection<int, DocumentEvent>
     */
    public function getEvents(): Collection
    {
        return $this->events;
    }

    public function addEvent(DocumentEvent $event): static
    {
        if (!$this->events->contains($event)) {
            $this->events->add($event);
            $event->setInvoice($this);
        }

        return $this;
    }

    public function getDirection(): ?InvoiceDirection
    {
        return $this->direction;
    }

    public function setDirection(?InvoiceDirection $direction): static
    {
        $this->direction = $direction;

        return $this;
    }

    public function getAnafMessageId(): ?string
    {
        return $this->anafMessageId;
    }

    public function setAnafMessageId(?string $anafMessageId): static
    {
        $this->anafMessageId = $anafMessageId;

        return $this;
    }

    public function getSenderCif(): ?string
    {
        return $this->senderCif;
    }

    public function setSenderCif(?string $senderCif): static
    {
        $this->senderCif = $senderCif;

        return $this;
    }

    public function getReceiverCif(): ?string
    {
        return $this->receiverCif;
    }

    public function setReceiverCif(?string $receiverCif): static
    {
        $this->receiverCif = $receiverCif;

        return $this;
    }

    public function getSenderName(): ?string
    {
        return $this->senderName;
    }

    public function setSenderName(?string $senderName): static
    {
        $this->senderName = $senderName;

        return $this;
    }

    public function getReceiverName(): ?string
    {
        return $this->receiverName;
    }

    public function setReceiverName(?string $receiverName): static
    {
        $this->receiverName = $receiverName;

        return $this;
    }

    public function getSyncedAt(): ?\DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function setSyncedAt(?\DateTimeImmutable $syncedAt): static
    {
        $this->syncedAt = $syncedAt;

        return $this;
    }

    public function isEditable(): bool
    {
        if (in_array($this->status, [DocumentStatus::CANCELLED, DocumentStatus::SENT_TO_PROVIDER], true)) {
            return false;
        }

        // Rejected by ANAF — allow editing so user can fix and resubmit
        if ($this->status === DocumentStatus::REJECTED) {
            return true;
        }

        return $this->anafUploadId === null;
    }

    public function isDeletable(): bool
    {
        return in_array($this->status, [DocumentStatus::DRAFT, DocumentStatus::CANCELLED], true);
    }

    #[Groups(['invoice:list'])]
    public function getClientName(): ?string
    {
        return $this->client?->getName();
    }

    public function getSignatureContent(): ?string
    {
        return $this->signatureContent;
    }

    public function setSignatureContent(?string $signatureContent): static
    {
        $this->signatureContent = $signatureContent;

        return $this;
    }

    public function getSignatureValid(): ?bool
    {
        return $this->signatureValid;
    }

    public function setSignatureValid(?bool $signatureValid): static
    {
        $this->signatureValid = $signatureValid;

        return $this;
    }

    public function isDuplicate(): bool
    {
        return $this->isDuplicate;
    }

    public function setIsDuplicate(bool $isDuplicate): static
    {
        $this->isDuplicate = $isDuplicate;

        return $this;
    }

    public function isLateSubmission(): bool
    {
        return $this->isLateSubmission;
    }

    public function setIsLateSubmission(bool $isLateSubmission): static
    {
        $this->isLateSubmission = $isLateSubmission;

        return $this;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function setPaidAt(?\DateTimeImmutable $paidAt): static
    {
        $this->paidAt = $paidAt;

        return $this;
    }

    public function getPaymentMethod(): ?string
    {
        return $this->paymentMethod;
    }

    public function setPaymentMethod(?string $paymentMethod): static
    {
        $this->paymentMethod = $paymentMethod;

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

    public function getCancellationReason(): ?string
    {
        return $this->cancellationReason;
    }

    public function setCancellationReason(?string $cancellationReason): static
    {
        $this->cancellationReason = $cancellationReason;

        return $this;
    }

    public function getScheduledSendAt(): ?\DateTimeImmutable
    {
        return $this->scheduledSendAt;
    }

    public function setScheduledSendAt(?\DateTimeImmutable $scheduledSendAt): static
    {
        $this->scheduledSendAt = $scheduledSendAt;

        return $this;
    }

    public function getScheduledEmailAt(): ?\DateTimeImmutable
    {
        return $this->scheduledEmailAt;
    }

    public function setScheduledEmailAt(?\DateTimeImmutable $scheduledEmailAt): static
    {
        $this->scheduledEmailAt = $scheduledEmailAt;

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

    public function getSupplier(): ?Supplier
    {
        return $this->supplier;
    }

    public function setSupplier(?Supplier $supplier): static
    {
        $this->supplier = $supplier;

        return $this;
    }

    /**
     * @return Collection<int, InvoiceAttachment>
     */
    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    public function addAttachment(InvoiceAttachment $attachment): static
    {
        if (!$this->attachments->contains($attachment)) {
            $this->attachments->add($attachment);
            $attachment->setInvoice($this);
        }

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

    /**
     * @return Collection<int, Payment>
     */
    public function getPayments(): Collection
    {
        return $this->payments;
    }

    public function addPayment(Payment $payment): static
    {
        if (!$this->payments->contains($payment)) {
            $this->payments->add($payment);
            $payment->setInvoice($this);
        }

        return $this;
    }

    public function removePayment(Payment $payment): static
    {
        if ($this->payments->removeElement($payment)) {
            if ($payment->getInvoice() === $this) {
                $payment->setInvoice(null);
            }
        }

        return $this;
    }

    public function getAmountPaid(): string
    {
        return $this->amountPaid;
    }

    public function setAmountPaid(string $amountPaid): static
    {
        $this->amountPaid = $amountPaid;

        return $this;
    }

    #[Groups(['invoice:list', 'invoice:detail'])]
    public function getBalance(): string
    {
        return bcsub($this->total, $this->amountPaid, 2);
    }

    public function isPenaltyEnabled(): bool
    {
        return $this->penaltyEnabled;
    }

    public function setPenaltyEnabled(bool $penaltyEnabled): static
    {
        $this->penaltyEnabled = $penaltyEnabled;

        return $this;
    }

    public function getPenaltyPercentPerDay(): ?string
    {
        return $this->penaltyPercentPerDay;
    }

    public function setPenaltyPercentPerDay(?string $penaltyPercentPerDay): static
    {
        $this->penaltyPercentPerDay = $penaltyPercentPerDay;

        return $this;
    }

    public function getPenaltyGraceDays(): ?int
    {
        return $this->penaltyGraceDays;
    }

    public function setPenaltyGraceDays(?int $penaltyGraceDays): static
    {
        $this->penaltyGraceDays = $penaltyGraceDays;

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

    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    public function setIdempotencyKey(?string $idempotencyKey): static
    {
        $this->idempotencyKey = $idempotencyKey;

        return $this;
    }

    public function isTvaLaIncasare(): bool
    {
        return $this->tvaLaIncasare;
    }

    public function setTvaLaIncasare(bool $tvaLaIncasare): static
    {
        $this->tvaLaIncasare = $tvaLaIncasare;

        return $this;
    }

    public function isPlatitorTva(): bool
    {
        return $this->platitorTva;
    }

    public function setPlatitorTva(bool $platitorTva): static
    {
        $this->platitorTva = $platitorTva;

        return $this;
    }

    public function isPlataOnline(): bool
    {
        return $this->plataOnline;
    }

    public function setPlataOnline(bool $plataOnline): static
    {
        $this->plataOnline = $plataOnline;

        return $this;
    }

    public function isShowClientBalance(): bool
    {
        return $this->showClientBalance;
    }

    public function setShowClientBalance(bool $showClientBalance): static
    {
        $this->showClientBalance = $showClientBalance;

        return $this;
    }

    public function getClientBalanceExisting(): ?string
    {
        return $this->clientBalanceExisting;
    }

    public function setClientBalanceExisting(?string $clientBalanceExisting): static
    {
        $this->clientBalanceExisting = $clientBalanceExisting;

        return $this;
    }

    public function getClientBalanceOverdue(): ?string
    {
        return $this->clientBalanceOverdue;
    }

    public function setClientBalanceOverdue(?string $clientBalanceOverdue): static
    {
        $this->clientBalanceOverdue = $clientBalanceOverdue;

        return $this;
    }

    public function getTaxPointDate(): ?\DateTimeInterface
    {
        return $this->taxPointDate;
    }

    public function setTaxPointDate(?\DateTimeInterface $taxPointDate): static
    {
        $this->taxPointDate = $taxPointDate;

        return $this;
    }

    public function getTaxPointDateCode(): ?string
    {
        return $this->taxPointDateCode;
    }

    public function setTaxPointDateCode(?string $taxPointDateCode): static
    {
        $this->taxPointDateCode = $taxPointDateCode;

        return $this;
    }

    public function getBuyerReference(): ?string
    {
        return $this->buyerReference;
    }

    public function setBuyerReference(?string $buyerReference): static
    {
        $this->buyerReference = $buyerReference;

        return $this;
    }

    public function getReceivingAdviceReference(): ?string
    {
        return $this->receivingAdviceReference;
    }

    public function setReceivingAdviceReference(?string $receivingAdviceReference): static
    {
        $this->receivingAdviceReference = $receivingAdviceReference;

        return $this;
    }

    public function getDespatchAdviceReference(): ?string
    {
        return $this->despatchAdviceReference;
    }

    public function setDespatchAdviceReference(?string $despatchAdviceReference): static
    {
        $this->despatchAdviceReference = $despatchAdviceReference;

        return $this;
    }

    public function getTenderOrLotReference(): ?string
    {
        return $this->tenderOrLotReference;
    }

    public function setTenderOrLotReference(?string $tenderOrLotReference): static
    {
        $this->tenderOrLotReference = $tenderOrLotReference;

        return $this;
    }

    public function getInvoicedObjectIdentifier(): ?string
    {
        return $this->invoicedObjectIdentifier;
    }

    public function setInvoicedObjectIdentifier(?string $invoicedObjectIdentifier): static
    {
        $this->invoicedObjectIdentifier = $invoicedObjectIdentifier;

        return $this;
    }

    public function getBuyerAccountingReference(): ?string
    {
        return $this->buyerAccountingReference;
    }

    public function setBuyerAccountingReference(?string $buyerAccountingReference): static
    {
        $this->buyerAccountingReference = $buyerAccountingReference;

        return $this;
    }

    public function getBusinessProcessType(): ?string
    {
        return $this->businessProcessType;
    }

    public function setBusinessProcessType(?string $businessProcessType): static
    {
        $this->businessProcessType = $businessProcessType;

        return $this;
    }

    public function getPayeeName(): ?string
    {
        return $this->payeeName;
    }

    public function setPayeeName(?string $payeeName): static
    {
        $this->payeeName = $payeeName;

        return $this;
    }

    public function getPayeeIdentifier(): ?string
    {
        return $this->payeeIdentifier;
    }

    public function setPayeeIdentifier(?string $payeeIdentifier): static
    {
        $this->payeeIdentifier = $payeeIdentifier;

        return $this;
    }

    public function getPayeeLegalRegistrationIdentifier(): ?string
    {
        return $this->payeeLegalRegistrationIdentifier;
    }

    public function setPayeeLegalRegistrationIdentifier(?string $payeeLegalRegistrationIdentifier): static
    {
        $this->payeeLegalRegistrationIdentifier = $payeeLegalRegistrationIdentifier;

        return $this;
    }
}
