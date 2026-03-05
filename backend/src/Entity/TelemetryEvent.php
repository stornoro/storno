<?php

namespace App\Entity;

use App\Doctrine\Type\UuidType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Index(columns: ['company_id', 'event', 'created_at'], name: 'idx_telemetry_company_event')]
#[ORM\Index(columns: ['user_id', 'created_at'], name: 'idx_telemetry_user')]
class TelemetryEvent
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private ?Uuid $id = null;

    #[ORM\Column(type: UuidType::NAME)]
    private ?Uuid $userId = null;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $companyId = null;

    #[ORM\Column(length: 100)]
    private ?string $event = null;

    #[ORM\Column(type: Types::JSON)]
    private array $properties = [];

    #[ORM\Column(length: 20)]
    private ?string $platform = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $appVersion = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getUserId(): ?Uuid
    {
        return $this->userId;
    }

    public function setUserId(Uuid $userId): static
    {
        $this->userId = $userId;

        return $this;
    }

    public function getCompanyId(): ?Uuid
    {
        return $this->companyId;
    }

    public function setCompanyId(?Uuid $companyId): static
    {
        $this->companyId = $companyId;

        return $this;
    }

    public function getEvent(): ?string
    {
        return $this->event;
    }

    public function setEvent(string $event): static
    {
        $this->event = $event;

        return $this;
    }

    public function getProperties(): array
    {
        return $this->properties;
    }

    public function setProperties(array $properties): static
    {
        $this->properties = $properties;

        return $this;
    }

    public function getPlatform(): ?string
    {
        return $this->platform;
    }

    public function setPlatform(string $platform): static
    {
        $this->platform = $platform;

        return $this;
    }

    public function getAppVersion(): ?string
    {
        return $this->appVersion;
    }

    public function setAppVersion(?string $appVersion): static
    {
        $this->appVersion = $appVersion;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
