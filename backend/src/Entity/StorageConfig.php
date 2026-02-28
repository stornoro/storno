<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Repository\StorageConfigRepository;
use Doctrine\ORM\Mapping as ORM;
use App\Doctrine\Type\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: StorageConfigRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_storage_config_organization', columns: ['organization_id'])]
class StorageConfig
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['storage:read'])]
    private ?Uuid $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Organization $organization = null;

    #[ORM\Column(length: 30)]
    #[Groups(['storage:read'])]
    private string $provider = 'aws_s3';

    #[ORM\Column(type: 'text')]
    private string $encryptedCredentials = '';

    #[ORM\Column(length: 255)]
    #[Groups(['storage:read'])]
    private string $bucket = '';

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['storage:read'])]
    private ?string $region = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['storage:read'])]
    private ?string $endpoint = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['storage:read'])]
    private ?string $prefix = 'documents';

    #[ORM\Column]
    #[Groups(['storage:read'])]
    private bool $forcePathStyle = false;

    #[ORM\Column]
    #[Groups(['storage:read'])]
    private bool $isActive = true;

    #[ORM\Column(nullable: true)]
    #[Groups(['storage:read'])]
    private ?\DateTimeImmutable $lastTestedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
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

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): static
    {
        $this->provider = $provider;

        return $this;
    }

    public function getEncryptedCredentials(): string
    {
        return $this->encryptedCredentials;
    }

    public function setEncryptedCredentials(string $encryptedCredentials): static
    {
        $this->encryptedCredentials = $encryptedCredentials;

        return $this;
    }

    public function getBucket(): string
    {
        return $this->bucket;
    }

    public function setBucket(string $bucket): static
    {
        $this->bucket = $bucket;

        return $this;
    }

    public function getRegion(): ?string
    {
        return $this->region;
    }

    public function setRegion(?string $region): static
    {
        $this->region = $region;

        return $this;
    }

    public function getEndpoint(): ?string
    {
        return $this->endpoint;
    }

    public function setEndpoint(?string $endpoint): static
    {
        $this->endpoint = $endpoint;

        return $this;
    }

    public function getPrefix(): ?string
    {
        return $this->prefix;
    }

    public function setPrefix(?string $prefix): static
    {
        $this->prefix = $prefix;

        return $this;
    }

    public function isForcePathStyle(): bool
    {
        return $this->forcePathStyle;
    }

    public function setForcePathStyle(bool $forcePathStyle): static
    {
        $this->forcePathStyle = $forcePathStyle;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getLastTestedAt(): ?\DateTimeImmutable
    {
        return $this->lastTestedAt;
    }

    public function setLastTestedAt(?\DateTimeImmutable $lastTestedAt): static
    {
        $this->lastTestedAt = $lastTestedAt;

        return $this;
    }
}
