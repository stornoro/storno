<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Entity\Traits\SoftDeletableTrait;
use App\Enum\DocumentType;
use App\Repository\RecurringInvoiceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Doctrine\Type\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: RecurringInvoiceRepository::class)]
#[ORM\Index(name: 'idx_recurring_invoice_company_active_next', columns: ['company_id', 'is_active', 'next_issuance_date', 'deleted_at'])]
class RecurringInvoice
{
    use AuditableTrait;
    use SoftDeletableTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['recurring_invoice:list', 'recurring_invoice:detail'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Company $company = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['recurring_invoice:list', 'recurring_invoice:detail'])]
    private ?Client $client = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['recurring_invoice:detail'])]
    private ?DocumentSeries $documentSeries = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['recurring_invoice:list', 'recurring_invoice:detail'])]
    private ?string $reference = null;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['recurring_invoice:list', 'recurring_invoice:detail'])]
    private bool $isActive = true;

    #[ORM\Column(length: 20, enumType: DocumentType::class)]
    #[Groups(['recurring_invoice:list', 'recurring_invoice:detail'])]
    private DocumentType $documentType = DocumentType::INVOICE;

    #[ORM\Column(length: 3)]
    #[Groups(['recurring_invoice:list', 'recurring_invoice:detail'])]
    private string $currency = 'RON';

    #[ORM\Column(length: 40, nullable: true)]
    #[Groups(['recurring_invoice:list', 'recurring_invoice:detail'])]
    private ?string $invoiceTypeCode = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['recurring_invoice:detail'])]
    private bool $autoEmailEnabled = false;

    #[ORM\Column(length: 5, nullable: true)]
    #[Groups(['recurring_invoice:detail'])]
    private ?string $autoEmailTime = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['recurring_invoice:detail'])]
    private int $autoEmailDayOffset = 0;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['recurring_invoice:detail'])]
    private bool $penaltyEnabled = false;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    #[Groups(['recurring_invoice:detail'])]
    private ?string $penaltyPercentPerDay = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['recurring_invoice:detail'])]
    private ?int $penaltyGraceDays = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['recurring_invoice:detail'])]
    private ?string $dueDateType = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['recurring_invoice:detail'])]
    private ?int $dueDateDays = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['recurring_invoice:detail'])]
    private ?int $dueDateFixedDay = null;

    #[ORM\Column(length: 20)]
    #[Groups(['recurring_invoice:list', 'recurring_invoice:detail'])]
    private string $frequency = 'monthly';

    #[ORM\Column]
    #[Groups(['recurring_invoice:list', 'recurring_invoice:detail'])]
    private int $frequencyDay = 1;

    #[ORM\Column(nullable: true)]
    #[Groups(['recurring_invoice:detail'])]
    private ?int $frequencyMonth = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Groups(['recurring_invoice:list', 'recurring_invoice:detail'])]
    private ?\DateTimeInterface $nextIssuanceDate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['recurring_invoice:detail'])]
    private ?\DateTimeInterface $stopDate = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['recurring_invoice:list', 'recurring_invoice:detail'])]
    private ?\DateTimeImmutable $lastIssuedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['recurring_invoice:list', 'recurring_invoice:detail'])]
    private ?string $lastInvoiceNumber = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['recurring_invoice:detail'])]
    private ?string $notes = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['recurring_invoice:detail'])]
    private ?string $paymentTerms = null;

    #[ORM\OneToMany(mappedBy: 'recurringInvoice', targetEntity: RecurringInvoiceLine::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Groups(['recurring_invoice:detail'])]
    private Collection $lines;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->lines = new ArrayCollection();
        $this->nextIssuanceDate = new \DateTime();
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

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): static
    {
        $this->reference = $reference;

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

    public function getDocumentType(): DocumentType
    {
        return $this->documentType;
    }

    public function setDocumentType(DocumentType $documentType): static
    {
        $this->documentType = $documentType;

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

    public function isAutoEmailEnabled(): bool
    {
        return $this->autoEmailEnabled;
    }

    public function setAutoEmailEnabled(bool $autoEmailEnabled): static
    {
        $this->autoEmailEnabled = $autoEmailEnabled;

        return $this;
    }

    public function getAutoEmailTime(): ?string
    {
        return $this->autoEmailTime;
    }

    public function setAutoEmailTime(?string $autoEmailTime): static
    {
        $this->autoEmailTime = $autoEmailTime;

        return $this;
    }

    public function getAutoEmailDayOffset(): int
    {
        return $this->autoEmailDayOffset;
    }

    public function setAutoEmailDayOffset(int $autoEmailDayOffset): static
    {
        $this->autoEmailDayOffset = $autoEmailDayOffset;

        return $this;
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

    public function getDueDateType(): ?string
    {
        return $this->dueDateType;
    }

    public function setDueDateType(?string $dueDateType): static
    {
        $this->dueDateType = $dueDateType;

        return $this;
    }

    public function getDueDateDays(): ?int
    {
        return $this->dueDateDays;
    }

    public function setDueDateDays(?int $dueDateDays): static
    {
        $this->dueDateDays = $dueDateDays;

        return $this;
    }

    public function getDueDateFixedDay(): ?int
    {
        return $this->dueDateFixedDay;
    }

    public function setDueDateFixedDay(?int $dueDateFixedDay): static
    {
        $this->dueDateFixedDay = $dueDateFixedDay;

        return $this;
    }

    public function getFrequency(): string
    {
        return $this->frequency;
    }

    public function setFrequency(string $frequency): static
    {
        $this->frequency = $frequency;

        return $this;
    }

    public function getFrequencyDay(): int
    {
        return $this->frequencyDay;
    }

    public function setFrequencyDay(int $frequencyDay): static
    {
        $this->frequencyDay = $frequencyDay;

        return $this;
    }

    public function getFrequencyMonth(): ?int
    {
        return $this->frequencyMonth;
    }

    public function setFrequencyMonth(?int $frequencyMonth): static
    {
        $this->frequencyMonth = $frequencyMonth;

        return $this;
    }

    public function getNextIssuanceDate(): ?\DateTimeInterface
    {
        return $this->nextIssuanceDate;
    }

    public function setNextIssuanceDate(?\DateTimeInterface $nextIssuanceDate): static
    {
        $this->nextIssuanceDate = $nextIssuanceDate;

        return $this;
    }

    public function getStopDate(): ?\DateTimeInterface
    {
        return $this->stopDate;
    }

    public function setStopDate(?\DateTimeInterface $stopDate): static
    {
        $this->stopDate = $stopDate;

        return $this;
    }

    public function getLastIssuedAt(): ?\DateTimeImmutable
    {
        return $this->lastIssuedAt;
    }

    public function setLastIssuedAt(?\DateTimeImmutable $lastIssuedAt): static
    {
        $this->lastIssuedAt = $lastIssuedAt;

        return $this;
    }

    public function getLastInvoiceNumber(): ?string
    {
        return $this->lastInvoiceNumber;
    }

    public function setLastInvoiceNumber(?string $lastInvoiceNumber): static
    {
        $this->lastInvoiceNumber = $lastInvoiceNumber;

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

    /**
     * @return Collection<int, RecurringInvoiceLine>
     */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(RecurringInvoiceLine $line): static
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setRecurringInvoice($this);
        }

        return $this;
    }

    public function removeLine(RecurringInvoiceLine $line): static
    {
        if ($this->lines->removeElement($line)) {
            if ($line->getRecurringInvoice() === $this) {
                $line->setRecurringInvoice(null);
            }
        }

        return $this;
    }

    public function clearLines(): static
    {
        $this->lines->clear();

        return $this;
    }

    // ── Computed getters for list serialization ──────────────────────

    #[Groups(['recurring_invoice:list'])]
    public function getClientName(): ?string
    {
        return $this->client?->getName();
    }

    #[Groups(['recurring_invoice:list', 'recurring_invoice:detail'])]
    public function getSubtotal(): string
    {
        $subtotal = '0.00';
        foreach ($this->lines as $line) {
            $subtotal = bcadd($subtotal, $line->getLineTotal(), 2);
        }
        return $subtotal;
    }

    #[Groups(['recurring_invoice:list', 'recurring_invoice:detail'])]
    public function getVatTotal(): string
    {
        $vatTotal = '0.00';
        foreach ($this->lines as $line) {
            $vatTotal = bcadd($vatTotal, $line->getVatAmount(), 2);
        }
        return $vatTotal;
    }

    #[Groups(['recurring_invoice:list', 'recurring_invoice:detail'])]
    public function getTotal(): string
    {
        return bcadd($this->getSubtotal(), $this->getVatTotal(), 2);
    }

    // ── Estimated total with exchange rate conversion (non-persisted) ──

    private ?string $estimatedSubtotal = null;
    private ?string $estimatedVatTotal = null;
    private ?string $estimatedTotal = null;

    public function setEstimatedAmounts(?string $subtotal, ?string $vatTotal, ?string $total): static
    {
        $this->estimatedSubtotal = $subtotal;
        $this->estimatedVatTotal = $vatTotal;
        $this->estimatedTotal = $total;
        return $this;
    }

    #[Groups(['recurring_invoice:list', 'recurring_invoice:detail'])]
    public function getEstimatedSubtotal(): ?string
    {
        return $this->estimatedSubtotal;
    }

    #[Groups(['recurring_invoice:list', 'recurring_invoice:detail'])]
    public function getEstimatedVatTotal(): ?string
    {
        return $this->estimatedVatTotal;
    }

    #[Groups(['recurring_invoice:list', 'recurring_invoice:detail'])]
    public function getEstimatedTotal(): ?string
    {
        return $this->estimatedTotal;
    }

    public function hasReferenceCurrencyLines(): bool
    {
        foreach ($this->lines as $line) {
            if ($line->getReferenceCurrency()) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, float> $exchangeRates currency => rate (e.g. ['EUR' => 4.977])
     * @return array{subtotal: string, vatTotal: string, total: string}
     */
    public function computeEstimatedAmounts(array $exchangeRates): array
    {
        $subtotal = '0.00';
        $vatTotal = '0.00';
        foreach ($this->lines as $line) {
            $lineNet = $line->getLineTotal();
            $vatAmount = $line->getVatAmount();

            $refCurrency = $line->getReferenceCurrency();
            if ($refCurrency && $this->currency === 'RON' && isset($exchangeRates[$refCurrency])) {
                $rate = (string) $exchangeRates[$refCurrency];
                $markup = $line->getMarkupPercent() ? (string) $line->getMarkupPercent() : '0';
                $multiplier = bcmul($rate, bcadd('1', bcdiv($markup, '100', 6), 6), 6);
                $lineNet = bcmul($lineNet, $multiplier, 2);
                $vatAmount = bcmul($vatAmount, $multiplier, 2);
            }

            $subtotal = bcadd($subtotal, $lineNet, 2);
            $vatTotal = bcadd($vatTotal, $vatAmount, 2);
        }
        return [
            'subtotal' => $subtotal,
            'vatTotal' => $vatTotal,
            'total' => bcadd($subtotal, $vatTotal, 2),
        ];
    }
}
