<?php

namespace App\Entity;

use App\Enum\DocumentStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Doctrine\Type\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Index(name: 'idx_document_event_invoice_created', columns: ['invoice_id', 'created_at'])]
class DocumentEvent
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(inversedBy: 'events')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Invoice $invoice = null;

    #[ORM\Column(length: 30, nullable: true, enumType: DocumentStatus::class)]
    private ?DocumentStatus $previousStatus = null;

    #[ORM\Column(length: 30, enumType: DocumentStatus::class)]
    private DocumentStatus $newStatus;

    #[ORM\Column(type: Types::JSON)]
    private array $metadata = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $createdBy = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
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

    public function getPreviousStatus(): ?DocumentStatus
    {
        return $this->previousStatus;
    }

    public function setPreviousStatus(?DocumentStatus $previousStatus): static
    {
        $this->previousStatus = $previousStatus;

        return $this;
    }

    public function getNewStatus(): DocumentStatus
    {
        return $this->newStatus;
    }

    public function setNewStatus(DocumentStatus $newStatus): static
    {
        $this->newStatus = $newStatus;

        return $this;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function setMetadata(array $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }
}
