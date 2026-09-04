<?php

namespace App\Entity;

use App\Doctrine\Type\UuidType;
use App\Repository\SpvRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * A request ("solicitare") sent to ANAF SPV through `cerere` for a company:
 * a report (fisa rol, vector fiscal, obligatii de plata…), a copy of a filed
 * declaration, a duplicate recipisa, a certificate. ANAF answers asynchronously:
 * the document shows up in listaMesaje with our `id_solicitare` and the inbox
 * sync links it here.
 */
#[ORM\Entity(repositoryClass: SpvRequestRepository::class)]
#[ORM\Table(name: 'spv_request')]
#[ORM\Index(name: 'idx_spv_req_company_created', columns: ['company_id', 'created_at'])]
#[ORM\Index(name: 'idx_spv_req_company_anaf', columns: ['company_id', 'anaf_request_id'])]
class SpvRequest
{
    public const STATUS_PENDING = 'pending';     // prepared, waiting for the agent's answer from ANAF
    public const STATUS_REQUESTED = 'requested'; // ANAF accepted, id_solicitare known
    public const STATUS_ANSWERED = 'answered';   // the document arrived in the inbox
    public const STATUS_ERROR = 'error';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['spv_request:list'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Company $company = null;

    /** Exact ANAF `tip` string, e.g. "Fisa Rol", "D300", "Duplicat Recipisa". */
    #[ORM\Column(length: 255)]
    #[Groups(['spv_request:list'])]
    private string $requestType = '';

    /** @var array<string, string> an, luna, motiv, numar_inregistrare, cui_pui, lunai, lunas */
    #[ORM\Column(type: Types::JSON)]
    #[Groups(['spv_request:list'])]
    private array $params = [];

    /** ANAF `id_solicitare`. */
    #[ORM\Column(length: 64, nullable: true)]
    #[Groups(['spv_request:list'])]
    private ?string $anafRequestId = null;

    /** ANAF `titlu`, e.g. "Transmitere cerere tip D101". */
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['spv_request:list'])]
    private ?string $title = null;

    #[ORM\Column(length: 16)]
    #[Groups(['spv_request:list'])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['spv_request:list'])]
    private ?string $errorMessage = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $requestedBy = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?SpvDocument $answerDocument = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['spv_request:list'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['spv_request:list'])]
    private ?\DateTimeImmutable $answeredAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid { return $this->id; }
    public function getCompany(): ?Company { return $this->company; }
    public function setCompany(?Company $c): static { $this->company = $c; return $this; }
    public function getRequestType(): string { return $this->requestType; }
    public function setRequestType(string $t): static { $this->requestType = $t; return $this; }
    /** @return array<string, string> */
    public function getParams(): array { return $this->params; }
    /** @param array<string, string> $p */
    public function setParams(array $p): static { $this->params = $p; return $this; }
    public function getAnafRequestId(): ?string { return $this->anafRequestId; }
    public function setAnafRequestId(?string $v): static { $this->anafRequestId = $v; return $this; }
    public function getTitle(): ?string { return $this->title; }
    public function setTitle(?string $v): static { $this->title = $v; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): static { $this->status = $s; return $this; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function setErrorMessage(?string $m): static { $this->errorMessage = $m; return $this; }
    public function getRequestedBy(): ?User { return $this->requestedBy; }
    public function setRequestedBy(?User $u): static { $this->requestedBy = $u; return $this; }
    public function getAnswerDocument(): ?SpvDocument { return $this->answerDocument; }
    public function setAnswerDocument(?SpvDocument $d): static { $this->answerDocument = $d; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getAnsweredAt(): ?\DateTimeImmutable { return $this->answeredAt; }
    public function setAnsweredAt(?\DateTimeImmutable $d): static { $this->answeredAt = $d; return $this; }

    #[Groups(['spv_request:list'])]
    public function getAnswerDocumentId(): ?string
    {
        return $this->answerDocument?->getId()?->toRfc4122();
    }

    #[Groups(['spv_request:list'])]
    public function getRequestedByName(): ?string
    {
        $u = $this->requestedBy;
        if ($u === null) {
            return null;
        }
        $name = trim(((string) ($u->getFirstName() ?? '')) . ' ' . ((string) ($u->getLastName() ?? '')));

        return $name !== '' ? $name : $u->getEmail();
    }
}
