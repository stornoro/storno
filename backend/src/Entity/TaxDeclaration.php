<?php

namespace App\Entity;

use App\Doctrine\Type\UuidType;
use App\Entity\Traits\AuditableTrait;
use App\Entity\Traits\SoftDeletableTrait;
use App\Enum\DeclarationStatus;
use App\Enum\DeclarationType;
use App\Repository\TaxDeclarationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TaxDeclarationRepository::class)]
#[ORM\Index(name: 'idx_declaration_company_type', columns: ['company_id', 'type'])]
#[ORM\Index(name: 'idx_declaration_status', columns: ['status'])]
#[ORM\Index(name: 'idx_declaration_period', columns: ['company_id', 'year', 'month'])]
class TaxDeclaration
{
    use AuditableTrait;
    use SoftDeletableTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['declaration:list', 'declaration:detail'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Company $company = null;

    #[ORM\Column(length: 20, enumType: DeclarationType::class)]
    #[Groups(['declaration:list', 'declaration:detail'])]
    private DeclarationType $type;

    #[ORM\Column(length: 30, enumType: DeclarationStatus::class)]
    #[Groups(['declaration:list', 'declaration:detail'])]
    private DeclarationStatus $status = DeclarationStatus::DRAFT;

    #[ORM\Column(type: 'smallint')]
    #[Groups(['declaration:list', 'declaration:detail'])]
    private int $year;

    #[ORM\Column(type: 'smallint')]
    #[Groups(['declaration:list', 'declaration:detail'])]
    private int $month;

    #[ORM\Column(length: 20)]
    #[Groups(['declaration:list', 'declaration:detail'])]
    private string $periodType = 'monthly';

    #[ORM\Column(nullable: true)]
    #[Groups(['declaration:detail'])]
    private ?array $data = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['declaration:detail'])]
    private ?array $metadata = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['declaration:detail'])]
    private ?string $anafUploadId = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $xmlPath = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $recipisaPath = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['declaration:detail'])]
    private ?string $errorMessage = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['declaration:list', 'declaration:detail'])]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['declaration:list', 'declaration:detail'])]
    private ?\DateTimeImmutable $acceptedAt = null;

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

    public function getType(): DeclarationType
    {
        return $this->type;
    }

    public function setType(DeclarationType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getStatus(): DeclarationStatus
    {
        return $this->status;
    }

    public function setStatus(DeclarationStatus $status): static
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();

        if ($status === DeclarationStatus::SUBMITTED) {
            $this->submittedAt = new \DateTimeImmutable();
        }

        if ($status === DeclarationStatus::ACCEPTED) {
            $this->acceptedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getYear(): int
    {
        return $this->year;
    }

    public function setYear(int $year): static
    {
        $this->year = $year;

        return $this;
    }

    public function getMonth(): int
    {
        return $this->month;
    }

    public function setMonth(int $month): static
    {
        $this->month = $month;

        return $this;
    }

    public function getPeriodType(): string
    {
        return $this->periodType;
    }

    public function setPeriodType(string $periodType): static
    {
        $this->periodType = $periodType;

        return $this;
    }

    public function getData(): ?array
    {
        return $this->data;
    }

    public function setData(?array $data): static
    {
        $this->data = $data;

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

    public function getAnafUploadId(): ?string
    {
        return $this->anafUploadId;
    }

    public function setAnafUploadId(?string $anafUploadId): static
    {
        $this->anafUploadId = $anafUploadId;

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

    public function getRecipisaPath(): ?string
    {
        return $this->recipisaPath;
    }

    public function setRecipisaPath(?string $recipisaPath): static
    {
        $this->recipisaPath = $recipisaPath;

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

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function getAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }
}
