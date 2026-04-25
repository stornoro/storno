<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Repository\CashMovementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Doctrine\Type\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: CashMovementRepository::class)]
#[ORM\Index(name: 'idx_cashmove_company_date', columns: ['company_id', 'movement_date'])]
class CashMovement
{
    use AuditableTrait;

    public const KIND_DEPOSIT = 'deposit';        // depunere la banca (cash leaves the till)
    public const KIND_WITHDRAWAL = 'withdrawal';  // ridicare numerar (cash enters the till)
    public const KIND_OTHER = 'other';            // miscellaneous adjustment, with explicit direction
    public const KINDS = [self::KIND_DEPOSIT, self::KIND_WITHDRAWAL, self::KIND_OTHER];

    public const DIR_IN = 'in';
    public const DIR_OUT = 'out';
    public const DIRS = [self::DIR_IN, self::DIR_OUT];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['cashmove:list', 'cashmove:detail'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Company $company = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Groups(['cashmove:list', 'cashmove:detail'])]
    private ?\DateTimeImmutable $movementDate = null;

    #[ORM\Column(length: 20)]
    #[Groups(['cashmove:list', 'cashmove:detail'])]
    private string $kind = self::KIND_OTHER;

    #[ORM\Column(length: 5)]
    #[Groups(['cashmove:list', 'cashmove:detail'])]
    private string $direction = self::DIR_OUT;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2)]
    #[Groups(['cashmove:list', 'cashmove:detail'])]
    private string $amount = '0.00';

    #[ORM\Column(length: 3)]
    #[Groups(['cashmove:list', 'cashmove:detail'])]
    private string $currency = 'RON';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['cashmove:list', 'cashmove:detail'])]
    private ?string $description = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['cashmove:list', 'cashmove:detail'])]
    private ?string $documentNumber = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): ?Uuid { return $this->id; }
    public function getCompany(): ?Company { return $this->company; }
    public function setCompany(?Company $c): static { $this->company = $c; return $this; }
    public function getMovementDate(): ?\DateTimeImmutable { return $this->movementDate; }
    public function setMovementDate(?\DateTimeImmutable $d): static { $this->movementDate = $d; return $this; }
    public function getKind(): string { return $this->kind; }
    public function setKind(string $k): static { $this->kind = $k; return $this; }
    public function getDirection(): string { return $this->direction; }
    public function setDirection(string $d): static { $this->direction = $d; return $this; }
    public function getAmount(): string { return $this->amount; }
    public function setAmount(string $a): static { $this->amount = $a; return $this; }
    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $c): static { $this->currency = $c; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): static { $this->description = $d; return $this; }
    public function getDocumentNumber(): ?string { return $this->documentNumber; }
    public function setDocumentNumber(?string $n): static { $this->documentNumber = $n; return $this; }
}
