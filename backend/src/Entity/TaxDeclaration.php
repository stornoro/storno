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
use Symfony\Component\Serializer\Attribute\SerializedName;
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

    /** Serialized through getDataForApi(): attachments are exposed without their binary content. */
    #[ORM\Column(nullable: true)]
    private ?array $data = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['declaration:list', 'declaration:detail'])]
    private ?array $metadata = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['declaration:detail'])]
    private ?string $anafUploadId = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $xmlPath = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $pdfPath = null;

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

    /** The dosar (case file) this belongs to, when the user or the inbox sync grouped it. */
    #[ORM\ManyToOne(targetEntity: Dosar::class)]
    #[ORM\JoinColumn(name: 'dosar_id', nullable: true, onDelete: 'SET NULL')]
    private ?Dosar $dosar = null;

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

    /**
     * The declaration data as the API returns it: form attachments (`data.attachments`,
     * e.g. the scanned contract of a C168) carry only name, size and mime — never the
     * base64 content, which can be several megabytes per read.
     */
    #[Groups(['declaration:detail'])]
    #[SerializedName('data')]
    public function getDataForApi(): ?array
    {
        if ($this->data === null || !is_array($this->data['attachments'] ?? null)) {
            return $this->data;
        }

        $data = $this->data;
        $data['attachments'] = array_values(array_map(static function (mixed $attachment): mixed {
            if (!is_array($attachment) || !is_string($attachment['contentBase64'] ?? null)) {
                return $attachment;
            }
            $base64 = $attachment['contentBase64'];
            $size = intdiv(strlen($base64) * 3, 4) - strlen($base64) + strlen(rtrim($base64, '='));
            $public = array_diff_key($attachment, ['contentBase64' => true]);
            $public['size'] = $public['size'] ?? max(0, $size);
            $public['mime'] = $public['mime'] ?? (str_ends_with(strtolower((string) ($attachment['name'] ?? '')), '.pdf') ? 'application/pdf' : null);
            $public['stored'] = true;

            return $public;
        }, $this->data['attachments']));

        return $data;
    }

    /**
     * Merges attachments sent back by a client into the stored ones: an incoming
     * attachment without `contentBase64` (the shape the API returns) keeps the stored
     * content of the attachment with the same name, and a payload that omits
     * `attachments` altogether keeps the stored list. Missing names drop the file.
     */
    public static function mergeAttachments(?array $stored, array $incoming): array
    {
        $storedAttachments = is_array($stored['attachments'] ?? null) ? $stored['attachments'] : [];
        if ($storedAttachments === []) {
            return $incoming;
        }
        if (!array_key_exists('attachments', $incoming)) {
            $incoming['attachments'] = $storedAttachments;

            return $incoming;
        }
        if (!is_array($incoming['attachments'])) {
            return $incoming;
        }

        $byName = [];
        foreach ($storedAttachments as $attachment) {
            if (is_array($attachment) && isset($attachment['name'])) {
                $byName[(string) $attachment['name']] = $attachment;
            }
        }
        $incoming['attachments'] = array_values(array_map(static function (mixed $attachment) use ($byName): mixed {
            if (is_array($attachment) && !is_string($attachment['contentBase64'] ?? null) && isset($byName[(string) ($attachment['name'] ?? '')])) {
                return $byName[(string) $attachment['name']];
            }

            return $attachment;
        }, $incoming['attachments']));

        return $incoming;
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

    public function getPdfPath(): ?string
    {
        return $this->pdfPath;
    }

    public function setPdfPath(?string $pdfPath): static
    {
        $this->pdfPath = $pdfPath;

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

    #[Groups(['declaration:list', 'declaration:detail'])]
    public function getCompanyId(): ?string
    {
        return $this->company?->getId()?->toRfc4122();
    }
    public function getDosar(): ?Dosar { return $this->dosar; }
    public function setDosar(?Dosar $d): static { $this->dosar = $d; return $this; }

    #[Groups(['declaration:list', 'declaration:detail'])]
    public function getDosarId(): ?string
    {
        return $this->dosar?->getId()?->toRfc4122();
    }
}
