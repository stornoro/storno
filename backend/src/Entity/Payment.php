<?php

namespace App\Entity;

use App\Entity\ImportJob;
use App\Entity\Traits\AuditableTrait;
use App\Repository\PaymentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Doctrine\Type\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PaymentRepository::class)]
class Payment
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['payment:list', 'payment:detail'])]
    private ?Uuid $id = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Groups(['payment:list', 'payment:detail'])]
    private string $amount = '0.00';

    #[ORM\Column(length: 3)]
    #[Groups(['payment:list', 'payment:detail'])]
    private string $currency = 'RON';

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Groups(['payment:list', 'payment:detail'])]
    private ?\DateTimeInterface $paymentDate = null;

    #[ORM\Column(length: 50)]
    #[Groups(['payment:list', 'payment:detail'])]
    private string $paymentMethod = 'bank_transfer';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['payment:list', 'payment:detail'])]
    private ?string $reference = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['payment:list', 'payment:detail'])]
    private ?string $notes = null;

    #[ORM\Column]
    #[Groups(['payment:list', 'payment:detail'])]
    private bool $isReconciled = false;

    #[ORM\ManyToOne(inversedBy: 'payments')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Invoice $invoice = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Company $company = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ImportJob $importJob = null;

    // ── Chitanță: the cash receipt issued for this payment (a cash payment on an issued invoice).
    // The receipt documents the payment that already exists, it never records a second one.

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?DocumentSeries $receiptSeries = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['payment:list', 'payment:detail'])]
    private ?string $receiptNumber = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['payment:list', 'payment:detail'])]
    private ?\DateTimeInterface $receiptDate = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['payment:list', 'payment:detail'])]
    private ?\DateTimeImmutable $receiptIssuedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->paymentDate = new \DateTime();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
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

    public function getPaymentDate(): ?\DateTimeInterface
    {
        return $this->paymentDate;
    }

    public function setPaymentDate(\DateTimeInterface $paymentDate): static
    {
        $this->paymentDate = $paymentDate;

        return $this;
    }

    public function getPaymentMethod(): string
    {
        return $this->paymentMethod;
    }

    public function setPaymentMethod(string $paymentMethod): static
    {
        $this->paymentMethod = $paymentMethod;

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

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function isReconciled(): bool
    {
        return $this->isReconciled;
    }

    public function setIsReconciled(bool $isReconciled): static
    {
        $this->isReconciled = $isReconciled;

        return $this;
    }

    public function getInvoice(): ?Invoice
    {
        return $this->invoice;
    }

    public function setInvoice(?Invoice $invoice): static
    {
        $this->invoice = $invoice;

        return $this;
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

    #[Groups(['payment:list', 'payment:detail'])]
    #[SerializedName('createdAt')]
    public function getPaymentCreatedAt(): ?\DateTimeImmutable
    {
        return $this->getCreatedAt();
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

    public function getReceiptSeries(): ?DocumentSeries
    {
        return $this->receiptSeries;
    }

    public function setReceiptSeries(?DocumentSeries $series): static
    {
        $this->receiptSeries = $series;

        return $this;
    }

    #[Groups(['payment:list', 'payment:detail'])]
    #[SerializedName('receiptSeriesId')]
    public function getReceiptSeriesId(): ?string
    {
        return $this->receiptSeries?->getId()?->toRfc4122();
    }

    public function getReceiptNumber(): ?string
    {
        return $this->receiptNumber;
    }

    public function setReceiptNumber(?string $receiptNumber): static
    {
        $this->receiptNumber = $receiptNumber;

        return $this;
    }

    public function getReceiptDate(): ?\DateTimeInterface
    {
        return $this->receiptDate;
    }

    public function setReceiptDate(?\DateTimeInterface $receiptDate): static
    {
        $this->receiptDate = $receiptDate;

        return $this;
    }

    public function getReceiptIssuedAt(): ?\DateTimeImmutable
    {
        return $this->receiptIssuedAt;
    }

    public function setReceiptIssuedAt(?\DateTimeImmutable $at): static
    {
        $this->receiptIssuedAt = $at;

        return $this;
    }

    public function hasReceipt(): bool
    {
        return $this->receiptNumber !== null;
    }
}
