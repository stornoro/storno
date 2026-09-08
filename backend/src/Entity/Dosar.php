<?php

namespace App\Entity;

use App\Doctrine\Type\UuidType;
use App\Repository\DosarRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * A "dosar": the real-world object a person deals with ANAF about — a rental
 * contract, one year's Declarația unică, the company's periodic VAT returns,
 * its fiscal standing — grouping the declarations filed for it, the SPV
 * requests, the ANAF messages the inbox sync links to them, and the deadlines.
 */
#[ORM\Entity(repositoryClass: DosarRepository::class)]
#[ORM\Table(name: 'dosar')]
#[ORM\Index(name: 'idx_dosar_company_type', columns: ['company_id', 'type'])]
#[ORM\Index(name: 'idx_dosar_company_deadline', columns: ['company_id', 'deadline_at'])]
class Dosar
{
    public const TYPE_RENTAL_CONTRACT = 'rental_contract';
    public const TYPE_ANNUAL_RETURN = 'annual_return';
    public const TYPE_PERIODIC = 'periodic';
    public const TYPE_FISCAL_STATUS = 'fiscal_status';
    public const TYPE_GENERIC = 'generic';
    public const TYPES = [self::TYPE_RENTAL_CONTRACT, self::TYPE_ANNUAL_RETURN, self::TYPE_PERIODIC, self::TYPE_FISCAL_STATUS, self::TYPE_GENERIC];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_ATTENTION = 'attention'; // something to fix or decide (rejected filing, missing data)
    public const STATUS_CLOSED = 'closed';
    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_ATTENTION, self::STATUS_CLOSED];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['dosar:list', 'dosar:detail'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Company $company = null;

    #[ORM\Column(length: 32)]
    #[Groups(['dosar:list', 'dosar:detail'])]
    private string $type = self::TYPE_GENERIC;

    #[ORM\Column(length: 255)]
    #[Groups(['dosar:list', 'dosar:detail'])]
    private string $title = '';

    /** Structured subject: for a rental contract {numar, data, adresa, chirias, chiriasCif, chirie, moneda, deLa, panaLa}; for an annual return {an}. */
    #[ORM\Column(type: Types::JSON)]
    #[Groups(['dosar:list', 'dosar:detail'])]
    private array $subject = [];

    #[ORM\Column(length: 16)]
    #[Groups(['dosar:list', 'dosar:detail'])]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['dosar:list', 'dosar:detail'])]
    private ?string $nextStep = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['dosar:list', 'dosar:detail'])]
    private ?\DateTimeImmutable $deadlineAt = null;

    /** What the deadline is about, e.g. "C168 termen 30 zile", "D212 25 mai" */
    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['dosar:list', 'dosar:detail'])]
    private ?string $deadlineLabel = null;

    /** Days-before values already notified for the current deadline, e.g. [30, 7] */
    #[ORM\Column(type: Types::JSON)]
    private array $deadlineNotified = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['dosar:detail'])]
    private ?string $notes = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['dosar:list', 'dosar:detail'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['dosar:list', 'dosar:detail'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?Uuid { return $this->id; }
    public function getCompany(): ?Company { return $this->company; }
    public function setCompany(?Company $c): static { $this->company = $c; return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $t): static { $this->type = $t; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $t): static { $this->title = $t; return $this; }
    /** @return array<string, mixed> */
    public function getSubject(): array { return $this->subject; }
    /** @param array<string, mixed> $s */
    public function setSubject(array $s): static { $this->subject = $s; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): static { $this->status = $s; return $this; }
    public function getNextStep(): ?string { return $this->nextStep; }
    public function setNextStep(?string $v): static { $this->nextStep = $v; return $this; }
    public function getDeadlineAt(): ?\DateTimeImmutable { return $this->deadlineAt; }
    public function setDeadlineAt(?\DateTimeImmutable $d): static { $this->deadlineAt = $d; $this->deadlineNotified = []; return $this; }
    public function getDeadlineLabel(): ?string { return $this->deadlineLabel; }
    public function setDeadlineLabel(?string $v): static { $this->deadlineLabel = $v; return $this; }
    /** @return list<int> */
    public function getDeadlineNotified(): array { return $this->deadlineNotified; }
    public function markDeadlineNotified(int $daysBefore): static { $this->deadlineNotified = array_values(array_unique([...$this->deadlineNotified, $daysBefore])); return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $n): static { $this->notes = $n; return $this; }
    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(?User $u): static { $this->createdBy = $u; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(): static { $this->updatedAt = new \DateTimeImmutable(); return $this; }

    #[Groups(['dosar:list', 'dosar:detail'])]
    public function getCompanyId(): ?string
    {
        return $this->company?->getId()?->toRfc4122();
    }

    /** Days until the deadline (negative when passed), null without a deadline. */
    #[Groups(['dosar:list', 'dosar:detail'])]
    public function getDaysToDeadline(): ?int
    {
        if ($this->deadlineAt === null) {
            return null;
        }
        $today = new \DateTimeImmutable('today');

        return (int) $today->diff($this->deadlineAt->setTime(0, 0))->format('%r%a');
    }
}
