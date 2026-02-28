<?php

namespace App\Entity;

use App\Doctrine\Type\UuidType;
use App\Repository\LicenseKeyRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * License keys issued to self-hosted instances.
 * Each key is bound to an Organization and reflects its subscription plan.
 */
#[ORM\Entity(repositoryClass: LicenseKeyRepository::class)]
#[ORM\Index(name: 'idx_license_key_key', columns: ['license_key'])]
#[ORM\Index(name: 'idx_license_key_org', columns: ['organization_id'])]
class LicenseKey
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private ?Uuid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Organization $organization = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $licenseKey;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $instanceName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $instanceUrl = null;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastValidatedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $activatedAt = null;

    #[ORM\Column(nullable: true)]
    private ?array $instanceMetrics = null;

    #[ORM\Column(nullable: true)]
    private ?array $lastViolations = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->licenseKey = bin2hex(random_bytes(32));
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getOrganization(): ?Organization
    {
        return $this->organization;
    }

    public function setOrganization(?Organization $organization): static
    {
        $this->organization = $organization;

        return $this;
    }

    public function getLicenseKey(): string
    {
        return $this->licenseKey;
    }

    public function getInstanceName(): ?string
    {
        return $this->instanceName;
    }

    public function setInstanceName(?string $instanceName): static
    {
        $this->instanceName = $instanceName;

        return $this;
    }

    public function getInstanceUrl(): ?string
    {
        return $this->instanceUrl;
    }

    public function setInstanceUrl(?string $instanceUrl): static
    {
        $this->instanceUrl = $instanceUrl;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getLastValidatedAt(): ?\DateTimeImmutable
    {
        return $this->lastValidatedAt;
    }

    public function setLastValidatedAt(?\DateTimeImmutable $lastValidatedAt): static
    {
        $this->lastValidatedAt = $lastValidatedAt;

        return $this;
    }

    public function getActivatedAt(): ?\DateTimeImmutable
    {
        return $this->activatedAt;
    }

    public function setActivatedAt(?\DateTimeImmutable $activatedAt): static
    {
        $this->activatedAt = $activatedAt;

        return $this;
    }

    public function getInstanceMetrics(): ?array
    {
        return $this->instanceMetrics;
    }

    public function setInstanceMetrics(?array $instanceMetrics): static
    {
        $this->instanceMetrics = $instanceMetrics;

        return $this;
    }

    public function getLastViolations(): ?array
    {
        return $this->lastViolations;
    }

    public function setLastViolations(?array $lastViolations): static
    {
        $this->lastViolations = $lastViolations;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
