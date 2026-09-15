<?php

namespace App\Entity;

use App\Doctrine\Type\UuidType;
use App\Repository\ExpiryItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Uid\Uuid;

/**
 * Something with an expiry date the company must renew: a vehicle document (RCA, ITP,
 * rovinietă, CASCO, tahograf, extinctor, trusă medicală, licență de transport, copie
 * conformă, leasing end) or a company-level one (certificat digital, contract, autorizație).
 * Renewing creates the next item and closes this one, so the history stays (`renewedFrom`).
 */
#[ORM\Entity(repositoryClass: ExpiryItemRepository::class)]
#[ORM\Table(name: 'expiry_item')]
#[ORM\Index(name: 'idx_expiry_company_expires', columns: ['company_id', 'expires_at'])]
#[ORM\Index(name: 'idx_expiry_vehicle', columns: ['vehicle_id'])]
class ExpiryItem
{
    public const KIND_RCA = 'rca';
    public const KIND_ITP = 'itp';
    public const KIND_ROVINIETA = 'rovinieta';
    public const KIND_CASCO = 'casco';
    public const KIND_TAHOGRAF = 'tahograf';
    public const KIND_EXTINCTOR = 'extinctor';
    public const KIND_TRUSA_MEDICALA = 'trusa_medicala';
    public const KIND_LICENTA_TRANSPORT = 'licenta_transport';
    public const KIND_COPIE_CONFORMA = 'copie_conforma';
    public const KIND_LEASING = 'leasing';
    public const KIND_CERTIFICAT_DIGITAL = 'certificat_digital';
    public const KIND_CONTRACT = 'contract';
    public const KIND_AUTORIZATIE = 'autorizatie';
    public const KIND_OTHER = 'other';

    public const KINDS = [
        self::KIND_RCA, self::KIND_ITP, self::KIND_ROVINIETA, self::KIND_CASCO, self::KIND_TAHOGRAF,
        self::KIND_EXTINCTOR, self::KIND_TRUSA_MEDICALA, self::KIND_LICENTA_TRANSPORT, self::KIND_COPIE_CONFORMA,
        self::KIND_LEASING, self::KIND_CERTIFICAT_DIGITAL, self::KIND_CONTRACT, self::KIND_AUTORIZATIE, self::KIND_OTHER,
    ];

    /** Kinds that belong to a vehicle; the rest are company-level (a vehicle may still be attached). */
    public const VEHICLE_KINDS = [
        self::KIND_RCA, self::KIND_ITP, self::KIND_ROVINIETA, self::KIND_CASCO, self::KIND_TAHOGRAF,
        self::KIND_EXTINCTOR, self::KIND_TRUSA_MEDICALA, self::KIND_LICENTA_TRANSPORT, self::KIND_COPIE_CONFORMA, self::KIND_LEASING,
    ];

    /** Usual validity in months, used to propose the next expiry when renewing */
    public const DEFAULT_MONTHS = [
        self::KIND_RCA => 12, self::KIND_ITP => 24, self::KIND_ROVINIETA => 12, self::KIND_CASCO => 12, self::KIND_TAHOGRAF => 24,
        self::KIND_EXTINCTOR => 12, self::KIND_TRUSA_MEDICALA => 36, self::KIND_LICENTA_TRANSPORT => 120, self::KIND_COPIE_CONFORMA => 120,
        self::KIND_CERTIFICAT_DIGITAL => 12, self::KIND_AUTORIZATIE => 12,
    ];

    public const STATUS_OK = 'ok';
    public const STATUS_DUE = 'due';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_RENEWED = 'renewed';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['expiry:list', 'expiry:detail'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Company $company = null;

    #[ORM\ManyToOne(targetEntity: Vehicle::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Vehicle $vehicle = null;

    #[ORM\Column(length: 32)]
    #[Groups(['expiry:list', 'expiry:detail'])]
    private string $kind = self::KIND_OTHER;

    #[ORM\Column(length: 160)]
    #[Groups(['expiry:list', 'expiry:detail'])]
    private string $label = '';

    /** Policy / certificate / contract number */
    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['expiry:list', 'expiry:detail'])]
    private ?string $number = null;

    /** Insurer, ITP station, leasing company … */
    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['expiry:list', 'expiry:detail'])]
    private ?string $provider = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    #[Groups(['expiry:list', 'expiry:detail'])]
    #[Context([DateTimeNormalizer::FORMAT_KEY => 'Y-m-d'])]
    private ?\DateTimeImmutable $validFrom = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Groups(['expiry:list', 'expiry:detail'])]
    #[Context([DateTimeNormalizer::FORMAT_KEY => 'Y-m-d'])]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::SMALLINT)]
    #[Groups(['expiry:list', 'expiry:detail'])]
    private int $remindDaysBefore = 30;

    /** Days-before thresholds already notified for this expiry, e.g. [30, 7] */
    #[ORM\Column(type: Types::JSON)]
    private array $notified = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['expiry:list', 'expiry:detail'])]
    private ?string $notes = null;

    /** The item this one renewed (history) */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ExpiryItem $renewedFrom = null;

    /** Set when the item was renewed (or closed by hand); closed items leave the upcoming list */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['expiry:list', 'expiry:detail'])]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['expiry:list', 'expiry:detail'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['expiry:list', 'expiry:detail'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->expiresAt = new \DateTimeImmutable('today');
    }

    public function getId(): ?Uuid { return $this->id; }
    public function getCompany(): ?Company { return $this->company; }
    public function setCompany(?Company $c): static { $this->company = $c; return $this; }
    public function getVehicle(): ?Vehicle { return $this->vehicle; }
    public function setVehicle(?Vehicle $v): static { $this->vehicle = $v; return $this; }
    public function getKind(): string { return $this->kind; }
    public function setKind(string $k): static { $this->kind = $k; return $this; }
    public function getLabel(): string { return $this->label; }
    public function setLabel(string $l): static { $this->label = $l; return $this; }
    public function getNumber(): ?string { return $this->number; }
    public function setNumber(?string $n): static { $this->number = $n; return $this; }
    public function getProvider(): ?string { return $this->provider; }
    public function setProvider(?string $p): static { $this->provider = $p; return $this; }
    public function getValidFrom(): ?\DateTimeImmutable { return $this->validFrom; }
    public function setValidFrom(?\DateTimeImmutable $d): static { $this->validFrom = $d; return $this; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    /** Changing the date restarts the reminders */
    public function setExpiresAt(\DateTimeImmutable $d): static
    {
        $d = $d->setTime(0, 0);
        if ($d != $this->expiresAt) {
            $this->notified = [];
        }
        $this->expiresAt = $d;

        return $this;
    }
    public function getRemindDaysBefore(): int { return $this->remindDaysBefore; }
    public function setRemindDaysBefore(int $d): static { $this->remindDaysBefore = max(0, min(365, $d)); return $this; }
    /** @return list<int> */
    public function getNotified(): array { return $this->notified; }
    public function markNotified(int $daysBefore): static { $this->notified = array_values(array_unique([...$this->notified, $daysBefore])); return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $n): static { $this->notes = $n; return $this; }
    public function getRenewedFrom(): ?ExpiryItem { return $this->renewedFrom; }
    public function setRenewedFrom(?ExpiryItem $i): static { $this->renewedFrom = $i; return $this; }
    public function getClosedAt(): ?\DateTimeImmutable { return $this->closedAt; }
    public function setClosedAt(?\DateTimeImmutable $d): static { $this->closedAt = $d; return $this; }
    public function isClosed(): bool { return $this->closedAt !== null; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(): static { $this->updatedAt = new \DateTimeImmutable(); return $this; }

    #[Groups(['expiry:list', 'expiry:detail'])]
    public function getCompanyId(): ?string
    {
        return $this->company?->getId()?->toRfc4122();
    }

    #[Groups(['expiry:list', 'expiry:detail'])]
    public function getVehicleId(): ?string
    {
        return $this->vehicle?->getId()?->toRfc4122();
    }

    /** @return array{id: string, plate: string, displayName: string}|null */
    #[Groups(['expiry:list', 'expiry:detail'])]
    #[SerializedName('vehicle')]
    public function getVehicleSummary(): ?array
    {
        return $this->vehicle ? ['id' => (string) $this->vehicle->getId(), 'plate' => $this->vehicle->getPlate(), 'displayName' => $this->vehicle->getDisplayName()] : null;
    }

    #[Groups(['expiry:list', 'expiry:detail'])]
    public function getRenewedFromId(): ?string
    {
        return $this->renewedFrom?->getId()?->toRfc4122();
    }

    /** Days until the expiry (negative when passed) */
    #[Groups(['expiry:list', 'expiry:detail'])]
    public function getDaysLeft(): int
    {
        return $this->daysLeftOn(new \DateTimeImmutable('today'));
    }

    public function daysLeftOn(\DateTimeImmutable $today): int
    {
        return (int) $today->setTime(0, 0)->diff($this->expiresAt->setTime(0, 0))->format('%r%a');
    }

    /** ok | due (within remindDaysBefore) | expired | renewed (closed) */
    #[Groups(['expiry:list', 'expiry:detail'])]
    public function getStatus(): string
    {
        return $this->statusOn(new \DateTimeImmutable('today'));
    }

    public function statusOn(\DateTimeImmutable $today): string
    {
        if ($this->closedAt !== null) {
            return self::STATUS_RENEWED;
        }
        $days = $this->daysLeftOn($today);
        if ($days < 0) {
            return self::STATUS_EXPIRED;
        }

        return $days <= $this->remindDaysBefore ? self::STATUS_DUE : self::STATUS_OK;
    }
}
