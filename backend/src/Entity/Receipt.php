<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Entity\Traits\SoftDeletableTrait;
use App\Enum\ReceiptStatus;
use App\Repository\ReceiptRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Doctrine\Type\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ReceiptRepository::class)]
#[ORM\Index(name: 'idx_receipt_company_status_created', columns: ['company_id', 'status', 'deleted_at', 'created_at'])]
class Receipt
{
    use AuditableTrait;
    use SoftDeletableTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['receipt:list', 'receipt:detail'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Company $company = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['receipt:list', 'receipt:detail'])]
    private ?Client $client = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['receipt:detail'])]
    private ?DocumentSeries $documentSeries = null;

    #[ORM\Column(length: 255)]
    #[Groups(['receipt:list', 'receipt:detail'])]
    private ?string $number = null;

    #[ORM\Column(length: 20, enumType: ReceiptStatus::class)]
    #[Groups(['receipt:list', 'receipt:detail'])]
    private ReceiptStatus $status = ReceiptStatus::DRAFT;

    #[ORM\Column(length: 3)]
    #[Groups(['receipt:list', 'receipt:detail'])]
    private string $currency = 'RON';

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Groups(['receipt:list', 'receipt:detail'])]
    private ?\DateTimeInterface $issueDate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Groups(['receipt:list', 'receipt:detail'])]
    private string $subtotal = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Groups(['receipt:list', 'receipt:detail'])]
    private string $vatTotal = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Groups(['receipt:list', 'receipt:detail'])]
    private string $total = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Groups(['receipt:detail'])]
    private string $discount = '0.00';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['receipt:detail'])]
    private ?string $notes = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['receipt:detail'])]
    private ?string $mentions = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['receipt:detail'])]
    private ?string $internalNote = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['receipt:detail'])]
    private ?string $projectReference = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['receipt:detail'])]
    private ?string $issuerName = null;

    #[ORM\Column(length: 13, nullable: true)]
    #[Groups(['receipt:detail'])]
    private ?string $issuerId = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['receipt:detail'])]
    private ?string $salesAgent = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    #[Groups(['receipt:detail'])]
    private ?string $exchangeRate = null;

    // Receipt-specific fields
    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['receipt:list', 'receipt:detail'])]
    private ?string $paymentMethod = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    #[Groups(['receipt:detail'])]
    private ?string $cashPayment = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    #[Groups(['receipt:detail'])]
    private ?string $cardPayment = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    #[Groups(['receipt:detail'])]
    private ?string $otherPayment = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['receipt:detail'])]
    private ?string $cashRegisterName = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['receipt:list', 'receipt:detail'])]
    private ?string $fiscalNumber = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['receipt:list', 'receipt:detail'])]
    private ?string $customerName = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['receipt:detail'])]
    private ?string $customerCif = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['receipt:detail'])]
    private ?Invoice $convertedInvoice = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['receipt:detail'])]
    private ?\DateTimeImmutable $issuedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['receipt:detail'])]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\OneToMany(mappedBy: 'receipt', targetEntity: ReceiptLine::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Groups(['receipt:detail'])]
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

    public function getStatus(): ReceiptStatus
    {
        return $this->status;
    }

    public function setStatus(ReceiptStatus $status): static
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

    public function getExchangeRate(): ?string
    {
        return $this->exchangeRate;
    }

    public function setExchangeRate(?string $exchangeRate): static
    {
        $this->exchangeRate = $exchangeRate;

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

    public function getCashPayment(): ?string
    {
        return $this->cashPayment;
    }

    public function setCashPayment(?string $cashPayment): static
    {
        $this->cashPayment = $cashPayment;

        return $this;
    }

    public function getCardPayment(): ?string
    {
        return $this->cardPayment;
    }

    public function setCardPayment(?string $cardPayment): static
    {
        $this->cardPayment = $cardPayment;

        return $this;
    }

    public function getOtherPayment(): ?string
    {
        return $this->otherPayment;
    }

    public function setOtherPayment(?string $otherPayment): static
    {
        $this->otherPayment = $otherPayment;

        return $this;
    }

    public function getCashRegisterName(): ?string
    {
        return $this->cashRegisterName;
    }

    public function setCashRegisterName(?string $cashRegisterName): static
    {
        $this->cashRegisterName = $cashRegisterName;

        return $this;
    }

    public function getFiscalNumber(): ?string
    {
        return $this->fiscalNumber;
    }

    public function setFiscalNumber(?string $fiscalNumber): static
    {
        $this->fiscalNumber = $fiscalNumber;

        return $this;
    }

    public function getCustomerName(): ?string
    {
        return $this->customerName;
    }

    public function setCustomerName(?string $customerName): static
    {
        $this->customerName = $customerName;

        return $this;
    }

    public function getCustomerCif(): ?string
    {
        return $this->customerCif;
    }

    public function setCustomerCif(?string $customerCif): static
    {
        $this->customerCif = $customerCif;

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
     * @return Collection<int, ReceiptLine>
     */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(ReceiptLine $line): static
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setReceipt($this);
        }

        return $this;
    }

    public function removeLine(ReceiptLine $line): static
    {
        if ($this->lines->removeElement($line)) {
            if ($line->getReceipt() === $this) {
                $line->setReceipt(null);
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
        return in_array($this->status, [ReceiptStatus::DRAFT, ReceiptStatus::ISSUED], true);
    }

    public function isDeletable(): bool
    {
        return in_array($this->status, [ReceiptStatus::DRAFT, ReceiptStatus::CANCELLED], true);
    }

    #[Groups(['receipt:list'])]
    public function getClientName(): ?string
    {
        return $this->client?->getName();
    }
}
