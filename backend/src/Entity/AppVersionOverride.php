<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Type\UuidType;
use App\Repository\AppVersionOverrideRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Per-platform override of the version metadata defined in
 * config/version.yaml. The VersionGateService merges these on top of
 * the YAML defaults at evaluation time so admins can flip the kill
 * switch from the dashboard without redeploying the backend.
 *
 * Each field is nullable — a NULL override means "fall back to YAML".
 * Clearing all fields (or deleting the row) restores the deployed
 * defaults.
 */
#[ORM\Entity(repositoryClass: AppVersionOverrideRepository::class)]
#[ORM\Table(name: 'app_version_override')]
#[ORM\UniqueConstraint(name: 'uniq_app_version_override_platform', columns: ['platform'])]
class AppVersionOverride
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private ?Uuid $id = null;

    #[ORM\Column(length: 20, unique: true)]
    private string $platform;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $minOverride = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $latestOverride = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $storeUrlOverride = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $releaseNotesUrlOverride = null;

    /**
     * Locale-keyed message ({"ro": "...", "en": "..."}). NULL means
     * "no override" — falls back to YAML.
     *
     * @var array<string, string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $messageOverride = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'updated_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    public function __construct(string $platform)
    {
        $this->id = Uuid::v7();
        $this->platform = $platform;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getPlatform(): string
    {
        return $this->platform;
    }

    public function getMinOverride(): ?string
    {
        return $this->minOverride;
    }

    public function setMinOverride(?string $value): self
    {
        $this->minOverride = $value !== null && $value !== '' ? $value : null;
        return $this;
    }

    public function getLatestOverride(): ?string
    {
        return $this->latestOverride;
    }

    public function setLatestOverride(?string $value): self
    {
        $this->latestOverride = $value !== null && $value !== '' ? $value : null;
        return $this;
    }

    public function getStoreUrlOverride(): ?string
    {
        return $this->storeUrlOverride;
    }

    public function setStoreUrlOverride(?string $value): self
    {
        $this->storeUrlOverride = $value !== null && $value !== '' ? $value : null;
        return $this;
    }

    public function getReleaseNotesUrlOverride(): ?string
    {
        return $this->releaseNotesUrlOverride;
    }

    public function setReleaseNotesUrlOverride(?string $value): self
    {
        $this->releaseNotesUrlOverride = $value !== null && $value !== '' ? $value : null;
        return $this;
    }

    /** @return array<string, string>|null */
    public function getMessageOverride(): ?array
    {
        return $this->messageOverride;
    }

    /** @param array<string, string>|null $value */
    public function setMessageOverride(?array $value): self
    {
        $this->messageOverride = $value !== null && $value !== [] ? $value : null;
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?User $user): self
    {
        $this->updatedBy = $user;
        return $this;
    }

    /**
     * True when at least one field is overridden — used by the admin UI
     * to render a "modified" badge per platform.
     */
    public function hasAnyOverride(): bool
    {
        return $this->minOverride !== null
            || $this->latestOverride !== null
            || $this->storeUrlOverride !== null
            || $this->releaseNotesUrlOverride !== null
            || ($this->messageOverride !== null && $this->messageOverride !== []);
    }
}
