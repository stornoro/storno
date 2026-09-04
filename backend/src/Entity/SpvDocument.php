<?php

namespace App\Entity;

use App\Doctrine\Type\UuidType;
use App\Enum\SpvDocumentCategory;
use App\Enum\SpvDocumentSeverity;
use App\Repository\SpvDocumentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * One message from the ANAF SPV inbox (listaMesaje) for a company, with the
 * attached PDF archived in the company's storage. Every message type is kept
 * (somatii, decizii, notificari, recipise...), classified for filtering and
 * alerting. Rows survive PDF retention cleanup: the file goes, the record stays.
 */
#[ORM\Entity(repositoryClass: SpvDocumentRepository::class)]
#[ORM\Table(name: 'spv_document')]
#[ORM\UniqueConstraint(name: 'uniq_spv_doc_company_anaf', columns: ['company_id', 'anaf_message_id'])]
#[ORM\Index(name: 'idx_spv_doc_company_created', columns: ['company_id', 'anaf_created_at'])]
#[ORM\Index(name: 'idx_spv_doc_company_category', columns: ['company_id', 'category'])]
#[ORM\Index(name: 'idx_spv_doc_company_severity', columns: ['company_id', 'severity'])]
class SpvDocument
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['spv_document:list', 'spv_document:detail'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Company $company = null;

    /** ANAF `id` of the message, also the descarcare id. */
    #[ORM\Column(length: 64)]
    #[Groups(['spv_document:list', 'spv_document:detail'])]
    private string $anafMessageId = '';

    /** Raw ANAF `tip`, e.g. "SOMATII", "Decizie inactivare". */
    #[ORM\Column(length: 255)]
    #[Groups(['spv_document:list', 'spv_document:detail'])]
    private string $messageType = '';

    #[ORM\Column(length: 32, enumType: SpvDocumentCategory::class)]
    #[Groups(['spv_document:list', 'spv_document:detail'])]
    private SpvDocumentCategory $category = SpvDocumentCategory::ALTELE;

    #[ORM\Column(length: 16, enumType: SpvDocumentSeverity::class)]
    #[Groups(['spv_document:list', 'spv_document:detail'])]
    private SpvDocumentSeverity $severity = SpvDocumentSeverity::NORMAL;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['spv_document:list', 'spv_document:detail'])]
    private ?string $cif = null;

    /** ANAF `detalii` — the human-readable description. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['spv_document:list', 'spv_document:detail'])]
    private ?string $details = null;

    #[ORM\Column(length: 64, nullable: true)]
    #[Groups(['spv_document:detail'])]
    private ?string $idSolicitare = null;

    /** Plain-language explanation of the message (what it is, which declaration/period, what to do), built by SpvDocumentSummarizer. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['spv_document:list', 'spv_document:detail'])]
    private ?string $summary = null;

    /** Same explanation in English. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['spv_document:list', 'spv_document:detail'])]
    private ?string $summaryEn = null;

    /** ANAF `data_creare` (when it reached the SPV inbox). */
    #[ORM\Column(nullable: true)]
    #[Groups(['spv_document:list', 'spv_document:detail'])]
    private ?\DateTimeImmutable $anafCreatedAt = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $pdfPath = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['spv_document:list', 'spv_document:detail'])]
    private ?string $fileName = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['spv_document:list', 'spv_document:detail'])]
    private ?int $fileSize = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['spv_document:list', 'spv_document:detail'])]
    private ?\DateTimeImmutable $downloadedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['spv_document:detail'])]
    private ?string $downloadError = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['spv_document:list', 'spv_document:detail'])]
    private ?\DateTimeImmutable $readAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['spv_document:detail'])]
    private ?\DateTimeImmutable $notifiedAt = null;

    /** Set when the PDF was removed by retention cleanup. */
    #[ORM\Column(nullable: true)]
    #[Groups(['spv_document:list', 'spv_document:detail'])]
    private ?\DateTimeImmutable $purgedAt = null;

    #[ORM\Column]
    #[Groups(['spv_document:list', 'spv_document:detail'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid { return $this->id; }

    public function getCompany(): ?Company { return $this->company; }
    public function setCompany(?Company $company): static { $this->company = $company; return $this; }

    public function getAnafMessageId(): string { return $this->anafMessageId; }
    public function setAnafMessageId(string $v): static { $this->anafMessageId = $v; return $this; }

    public function getMessageType(): string { return $this->messageType; }
    public function setMessageType(string $v): static { $this->messageType = mb_substr(trim($v), 0, 255); return $this; }

    public function getCategory(): SpvDocumentCategory { return $this->category; }
    public function setCategory(SpvDocumentCategory $v): static { $this->category = $v; return $this; }

    public function getSeverity(): SpvDocumentSeverity { return $this->severity; }
    public function setSeverity(SpvDocumentSeverity $v): static { $this->severity = $v; return $this; }

    public function getCif(): ?string { return $this->cif; }
    public function setCif(?string $v): static { $this->cif = $v; return $this; }

    public function getDetails(): ?string { return $this->details; }
    public function setDetails(?string $v): static { $this->details = $v; return $this; }

    public function getSummary(): ?string { return $this->summary; }
    public function setSummary(?string $v): static { $this->summary = $v; return $this; }
    public function getSummaryEn(): ?string { return $this->summaryEn; }
    public function setSummaryEn(?string $v): static { $this->summaryEn = $v; return $this; }
    public function getIdSolicitare(): ?string { return $this->idSolicitare; }
    public function setIdSolicitare(?string $v): static { $this->idSolicitare = $v; return $this; }

    public function getAnafCreatedAt(): ?\DateTimeImmutable { return $this->anafCreatedAt; }
    public function setAnafCreatedAt(?\DateTimeImmutable $v): static { $this->anafCreatedAt = $v; return $this; }

    public function getPdfPath(): ?string { return $this->pdfPath; }
    public function setPdfPath(?string $v): static { $this->pdfPath = $v; return $this; }

    public function getFileName(): ?string { return $this->fileName; }
    public function setFileName(?string $v): static { $this->fileName = $v; return $this; }

    public function getFileSize(): ?int { return $this->fileSize; }
    public function setFileSize(?int $v): static { $this->fileSize = $v; return $this; }

    public function getDownloadedAt(): ?\DateTimeImmutable { return $this->downloadedAt; }
    public function setDownloadedAt(?\DateTimeImmutable $v): static { $this->downloadedAt = $v; return $this; }

    public function getDownloadError(): ?string { return $this->downloadError; }
    public function setDownloadError(?string $v): static { $this->downloadError = $v; return $this; }

    public function getReadAt(): ?\DateTimeImmutable { return $this->readAt; }
    public function setReadAt(?\DateTimeImmutable $v): static { $this->readAt = $v; return $this; }

    public function getNotifiedAt(): ?\DateTimeImmutable { return $this->notifiedAt; }
    public function setNotifiedAt(?\DateTimeImmutable $v): static { $this->notifiedAt = $v; return $this; }

    public function getPurgedAt(): ?\DateTimeImmutable { return $this->purgedAt; }
    public function setPurgedAt(?\DateTimeImmutable $v): static { $this->purgedAt = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    #[Groups(['spv_document:list', 'spv_document:detail'])]
    public function getCategoryLabel(): string { return $this->category->label(); }

    #[Groups(['spv_document:list', 'spv_document:detail'])]
    public function isRead(): bool { return $this->readAt !== null; }

    #[Groups(['spv_document:list', 'spv_document:detail'])]
    public function getHasPdf(): bool { return $this->pdfPath !== null && $this->purgedAt === null; }
}
