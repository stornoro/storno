<?php

namespace App\Entity;

use App\Doctrine\Type\UuidType;
use App\Enum\EInvoiceProvider;
use App\Enum\EInvoiceSubmissionStatus;
use App\Repository\EInvoiceSubmissionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: EInvoiceSubmissionRepository::class)]
#[ORM\Index(name: 'idx_submission_invoice_provider', columns: ['invoice_id', 'provider'])]
#[ORM\Index(name: 'idx_submission_status', columns: ['provider', 'status'])]
class EInvoiceSubmission
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['einvoice_submission:list', 'einvoice_submission:detail'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Invoice $invoice = null;

    #[ORM\Column(length: 20, enumType: EInvoiceProvider::class)]
    #[Groups(['einvoice_submission:list', 'einvoice_submission:detail'])]
    private EInvoiceProvider $provider;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['einvoice_submission:list', 'einvoice_submission:detail'])]
    private ?string $externalId = null;

    #[ORM\Column(length: 30, enumType: EInvoiceSubmissionStatus::class)]
    #[Groups(['einvoice_submission:list', 'einvoice_submission:detail'])]
    private EInvoiceSubmissionStatus $status = EInvoiceSubmissionStatus::PENDING;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['einvoice_submission:detail'])]
    private ?string $errorMessage = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['einvoice_submission:detail'])]
    private ?array $metadata = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $xmlPath = null;

    #[ORM\Column]
    #[Groups(['einvoice_submission:list', 'einvoice_submission:detail'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    #[Groups(['einvoice_submission:list', 'einvoice_submission:detail'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
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

    public function getProvider(): EInvoiceProvider
    {
        return $this->provider;
    }

    public function setProvider(EInvoiceProvider $provider): static
    {
        $this->provider = $provider;

        return $this;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): static
    {
        $this->externalId = $externalId;

        return $this;
    }

    public function getStatus(): EInvoiceSubmissionStatus
    {
        return $this->status;
    }

    public function setStatus(EInvoiceSubmissionStatus $status): static
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): static
    {
        $this->metadata = $metadata;

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
