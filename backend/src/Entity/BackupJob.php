<?php

namespace App\Entity;

use App\Doctrine\Type\UuidType;
use App\Entity\Traits\AuditableTrait;
use App\Repository\BackupJobRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: BackupJobRepository::class)]
#[ORM\Index(name: 'idx_backup_job_company_status', columns: ['company_id', 'status'])]
class BackupJob
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['backup_job:list', 'backup_job:detail'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Company $company = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $initiatedBy = null;

    /**
     * 'backup', 'restore'
     */
    #[ORM\Column(length: 10)]
    #[Groups(['backup_job:list', 'backup_job:detail'])]
    private string $type = 'backup';

    /**
     * 'pending', 'processing', 'completed', 'failed'
     */
    #[ORM\Column(length: 20)]
    #[Groups(['backup_job:list', 'backup_job:detail'])]
    private string $status = 'pending';

    #[ORM\Column]
    #[Groups(['backup_job:list', 'backup_job:detail'])]
    private int $progress = 0;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['backup_job:detail'])]
    private ?string $currentStep = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['backup_job:detail'])]
    private ?string $storagePath = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['backup_job:list', 'backup_job:detail'])]
    private ?string $filename = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['backup_job:list', 'backup_job:detail'])]
    private ?int $fileSize = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['backup_job:detail'])]
    private ?array $metadata = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['backup_job:detail'])]
    private ?string $errorMessage = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['backup_job:list', 'backup_job:detail'])]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
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

    public function getInitiatedBy(): ?User
    {
        return $this->initiatedBy;
    }

    public function setInitiatedBy(?User $initiatedBy): static
    {
        $this->initiatedBy = $initiatedBy;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

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

    public function getProgress(): int
    {
        return $this->progress;
    }

    public function setProgress(int $progress): static
    {
        $this->progress = $progress;

        return $this;
    }

    public function getCurrentStep(): ?string
    {
        return $this->currentStep;
    }

    public function setCurrentStep(?string $currentStep): static
    {
        $this->currentStep = $currentStep;

        return $this;
    }

    public function getStoragePath(): ?string
    {
        return $this->storagePath;
    }

    public function setStoragePath(?string $storagePath): static
    {
        $this->storagePath = $storagePath;

        return $this;
    }

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    public function setFilename(?string $filename): static
    {
        $this->filename = $filename;

        return $this;
    }

    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }

    public function setFileSize(?int $fileSize): static
    {
        $this->fileSize = $fileSize;

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

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): static
    {
        $this->completedAt = $completedAt;

        return $this;
    }

    #[Groups(['backup_job:list', 'backup_job:detail'])]
    #[\Symfony\Component\Serializer\Attribute\SerializedName('createdAt')]
    public function getBackupCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }
}
