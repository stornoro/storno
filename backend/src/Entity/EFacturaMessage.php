<?php

namespace App\Entity;

use App\Repository\EFacturaMessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Doctrine\Type\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: EFacturaMessageRepository::class)]
#[ORM\Index(name: 'idx_efactura_msg_company', columns: ['company_id', 'created_at'])]
#[ORM\Index(name: 'idx_efactura_msg_type', columns: ['company_id', 'message_type'])]
#[ORM\Index(name: 'idx_efactura_msg_anaf_id', columns: ['anaf_message_id'])]
class EFacturaMessage
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['efactura_message:list', 'efactura_message:detail'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Company $company = null;

    #[ORM\Column(length: 255)]
    #[Groups(['efactura_message:list', 'efactura_message:detail'])]
    private ?string $anafMessageId = null;

    #[ORM\Column(length: 100)]
    #[Groups(['efactura_message:list', 'efactura_message:detail'])]
    private ?string $messageType = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['efactura_message:list', 'efactura_message:detail'])]
    private ?string $cif = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['efactura_message:list', 'efactura_message:detail'])]
    private ?string $details = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['efactura_message:list', 'efactura_message:detail'])]
    private ?string $uploadId = null;

    #[ORM\Column(length: 50)]
    #[Groups(['efactura_message:list', 'efactura_message:detail'])]
    private string $status = 'received'; // received, processed, error

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['efactura_message:list', 'efactura_message:detail'])]
    private ?string $errorMessage = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['efactura_message:list'])]
    private ?Invoice $invoice = null;

    #[ORM\Column]
    #[Groups(['efactura_message:list', 'efactura_message:detail'])]
    private \DateTimeImmutable $createdAt;

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

    public function getAnafMessageId(): ?string
    {
        return $this->anafMessageId;
    }

    public function setAnafMessageId(string $anafMessageId): static
    {
        $this->anafMessageId = $anafMessageId;

        return $this;
    }

    public function getMessageType(): ?string
    {
        return $this->messageType;
    }

    public function setMessageType(string $messageType): static
    {
        $this->messageType = $messageType;

        return $this;
    }

    public function getCif(): ?string
    {
        return $this->cif;
    }

    public function setCif(?string $cif): static
    {
        $this->cif = $cif;

        return $this;
    }

    public function getDetails(): ?string
    {
        return $this->details;
    }

    public function setDetails(?string $details): static
    {
        $this->details = $details;

        return $this;
    }

    public function getUploadId(): ?string
    {
        return $this->uploadId;
    }

    public function setUploadId(?string $uploadId): static
    {
        $this->uploadId = $uploadId;

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

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
