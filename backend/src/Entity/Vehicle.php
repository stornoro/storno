<?php

namespace App\Entity;

use App\Doctrine\Type\UuidType;
use App\Repository\VehicleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * A vehicle of the company (parc auto): the car, van or truck whose documents
 * (RCA, ITP, rovinietă, CASCO, tahograf …) are tracked as expiry items.
 */
#[ORM\Entity(repositoryClass: VehicleRepository::class)]
#[ORM\Table(name: 'vehicle')]
#[ORM\Index(name: 'idx_vehicle_company_plate', columns: ['company_id', 'plate'])]
class Vehicle
{
    public const OWNERSHIP_OWN = 'own';
    public const OWNERSHIP_LEASING = 'leasing';
    public const OWNERSHIP_RENTED = 'rented';
    public const OWNERSHIPS = [self::OWNERSHIP_OWN, self::OWNERSHIP_LEASING, self::OWNERSHIP_RENTED];

    public const FUELS = ['benzina', 'motorina', 'gpl', 'hibrid', 'electric', 'altul'];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['vehicle:list', 'vehicle:detail', 'expiry:list'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Company $company = null;

    #[ORM\Column(length: 20)]
    #[Groups(['vehicle:list', 'vehicle:detail', 'expiry:list'])]
    private string $plate = '';

    #[ORM\Column(length: 32, nullable: true)]
    #[Groups(['vehicle:list', 'vehicle:detail'])]
    private ?string $vin = null;

    #[ORM\Column(length: 60, nullable: true)]
    #[Groups(['vehicle:list', 'vehicle:detail', 'expiry:list'])]
    private ?string $make = null;

    #[ORM\Column(length: 60, nullable: true)]
    #[Groups(['vehicle:list', 'vehicle:detail', 'expiry:list'])]
    private ?string $model = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    #[Groups(['vehicle:list', 'vehicle:detail'])]
    private ?int $year = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['vehicle:list', 'vehicle:detail'])]
    private ?string $fuel = null;

    #[ORM\Column(length: 16)]
    #[Groups(['vehicle:list', 'vehicle:detail'])]
    private string $ownership = self::OWNERSHIP_OWN;

    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['vehicle:list', 'vehicle:detail'])]
    private ?string $driverName = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['vehicle:detail'])]
    private ?string $notes = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['vehicle:list', 'vehicle:detail'])]
    private bool $active = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['vehicle:list', 'vehicle:detail'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['vehicle:list', 'vehicle:detail'])]
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
    public function getPlate(): string { return $this->plate; }
    public function setPlate(string $p): static { $this->plate = $p; return $this; }
    public function getVin(): ?string { return $this->vin; }
    public function setVin(?string $v): static { $this->vin = $v; return $this; }
    public function getMake(): ?string { return $this->make; }
    public function setMake(?string $v): static { $this->make = $v; return $this; }
    public function getModel(): ?string { return $this->model; }
    public function setModel(?string $v): static { $this->model = $v; return $this; }
    public function getYear(): ?int { return $this->year; }
    public function setYear(?int $v): static { $this->year = $v; return $this; }
    public function getFuel(): ?string { return $this->fuel; }
    public function setFuel(?string $v): static { $this->fuel = $v; return $this; }
    public function getOwnership(): string { return $this->ownership; }
    public function setOwnership(string $v): static { $this->ownership = $v; return $this; }
    public function getDriverName(): ?string { return $this->driverName; }
    public function setDriverName(?string $v): static { $this->driverName = $v; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $v): static { $this->notes = $v; return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $v): static { $this->active = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(): static { $this->updatedAt = new \DateTimeImmutable(); return $this; }

    #[Groups(['vehicle:list', 'vehicle:detail'])]
    public function getCompanyId(): ?string
    {
        return $this->company?->getId()?->toRfc4122();
    }

    /** "B 123 ABC · Dacia Logan" for lists and notifications */
    #[Groups(['vehicle:list', 'vehicle:detail', 'expiry:list'])]
    public function getDisplayName(): string
    {
        $name = trim(($this->make ?? '') . ' ' . ($this->model ?? ''));

        return $name !== '' ? $this->plate . ' · ' . $name : $this->plate;
    }
}
