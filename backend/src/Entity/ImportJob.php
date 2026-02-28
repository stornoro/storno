<?php

namespace App\Entity;

use App\Doctrine\Type\UuidType;
use App\Entity\Traits\AuditableTrait;
use App\Repository\ImportJobRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ImportJobRepository::class)]
#[ORM\Index(name: 'idx_import_job_company_status', columns: ['company_id', 'status'])]
class ImportJob
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['import_job:list', 'import_job:detail'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Company $company = null;

    /**
     * 'clients', 'products', 'invoices_issued', 'invoices_received'
     */
    #[ORM\Column(length: 30)]
    #[Groups(['import_job:list', 'import_job:detail'])]
    private ?string $importType = null;

    /**
     * 'smartbill', 'saga', 'oblio', 'fgo', 'facturis_online', 'generic'
     */
    #[ORM\Column(length: 30)]
    #[Groups(['import_job:list', 'import_job:detail'])]
    private ?string $source = null;

    /**
     * 'csv', 'xlsx', 'saga_xml'
     */
    #[ORM\Column(length: 10)]
    #[Groups(['import_job:list', 'import_job:detail'])]
    private ?string $fileFormat = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['import_job:list', 'import_job:detail'])]
    private ?string $originalFilename = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['import_job:detail'])]
    private ?string $storagePath = null;

    /**
     * 'pending', 'preview', 'mapping', 'processing', 'completed', 'failed'
     */
    #[ORM\Column(length: 20)]
    #[Groups(['import_job:list', 'import_job:detail'])]
    private string $status = 'pending';

    #[ORM\Column(nullable: true)]
    #[Groups(['import_job:detail'])]
    private ?array $columnMapping = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['import_job:detail'])]
    private ?array $detectedColumns = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['import_job:detail'])]
    private ?array $suggestedMapping = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['import_job:detail'])]
    private ?array $previewData = null;

    #[ORM\Column]
    #[Groups(['import_job:list', 'import_job:detail'])]
    private int $totalRows = 0;

    #[ORM\Column]
    #[Groups(['import_job:list', 'import_job:detail'])]
    private int $createdCount = 0;

    #[ORM\Column]
    #[Groups(['import_job:list', 'import_job:detail'])]
    private int $updatedCount = 0;

    #[ORM\Column]
    #[Groups(['import_job:list', 'import_job:detail'])]
    private int $skippedCount = 0;

    #[ORM\Column]
    #[Groups(['import_job:list', 'import_job:detail'])]
    private int $errorCount = 0;

    #[ORM\Column(nullable: true)]
    #[Groups(['import_job:detail'])]
    private ?array $errors = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['import_job:detail'])]
    private ?array $importOptions = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['import_job:detail'])]
    private ?\DateTimeImmutable $processedAt = null;

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

    public function getImportType(): ?string
    {
        return $this->importType;
    }

    public function setImportType(string $importType): static
    {
        $this->importType = $importType;

        return $this;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getFileFormat(): ?string
    {
        return $this->fileFormat;
    }

    public function setFileFormat(string $fileFormat): static
    {
        $this->fileFormat = $fileFormat;

        return $this;
    }

    public function getOriginalFilename(): ?string
    {
        return $this->originalFilename;
    }

    public function setOriginalFilename(?string $originalFilename): static
    {
        $this->originalFilename = $originalFilename;

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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getColumnMapping(): ?array
    {
        return $this->columnMapping;
    }

    public function setColumnMapping(?array $columnMapping): static
    {
        $this->columnMapping = $columnMapping;

        return $this;
    }

    public function getDetectedColumns(): ?array
    {
        return $this->detectedColumns;
    }

    public function setDetectedColumns(?array $detectedColumns): static
    {
        $this->detectedColumns = $detectedColumns;

        return $this;
    }

    public function getSuggestedMapping(): ?array
    {
        return $this->suggestedMapping;
    }

    public function setSuggestedMapping(?array $suggestedMapping): static
    {
        $this->suggestedMapping = $suggestedMapping;

        return $this;
    }

    public function getPreviewData(): ?array
    {
        return $this->previewData;
    }

    public function setPreviewData(?array $previewData): static
    {
        $this->previewData = $previewData;

        return $this;
    }

    public function getTotalRows(): int
    {
        return $this->totalRows;
    }

    public function setTotalRows(int $totalRows): static
    {
        $this->totalRows = $totalRows;

        return $this;
    }

    public function getCreatedCount(): int
    {
        return $this->createdCount;
    }

    public function setCreatedCount(int $createdCount): static
    {
        $this->createdCount = $createdCount;

        return $this;
    }

    public function getUpdatedCount(): int
    {
        return $this->updatedCount;
    }

    public function setUpdatedCount(int $updatedCount): static
    {
        $this->updatedCount = $updatedCount;

        return $this;
    }

    public function getSkippedCount(): int
    {
        return $this->skippedCount;
    }

    public function setSkippedCount(int $skippedCount): static
    {
        $this->skippedCount = $skippedCount;

        return $this;
    }

    public function getErrorCount(): int
    {
        return $this->errorCount;
    }

    public function setErrorCount(int $errorCount): static
    {
        $this->errorCount = $errorCount;

        return $this;
    }

    public function getErrors(): ?array
    {
        return $this->errors;
    }

    public function setErrors(?array $errors): static
    {
        $this->errors = $errors;

        return $this;
    }

    public function getImportOptions(): ?array
    {
        return $this->importOptions;
    }

    public function setImportOptions(?array $importOptions): static
    {
        $this->importOptions = $importOptions;

        return $this;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function setProcessedAt(?\DateTimeImmutable $processedAt): static
    {
        $this->processedAt = $processedAt;

        return $this;
    }

    #[Groups(['import_job:list', 'import_job:detail'])]
    #[\Symfony\Component\Serializer\Attribute\SerializedName('createdAt')]
    public function getImportCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }
}
