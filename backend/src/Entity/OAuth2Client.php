<?php

namespace App\Entity;

use App\Repository\OAuth2ClientRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Doctrine\Type\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: OAuth2ClientRepository::class)]
#[ORM\Index(columns: ['client_id'], name: 'idx_oauth2_client_client_id')]
class OAuth2Client
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['oauth2_client:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Ignore]
    private ?Organization $organization = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Ignore]
    private ?User $createdBy = null;

    #[ORM\Column(length: 255)]
    #[Groups(['oauth2_client:read'])]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['oauth2_client:read'])]
    private ?string $description = null;

    #[ORM\Column(length: 68, unique: true)]
    #[Groups(['oauth2_client:read'])]
    private ?string $clientId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $clientSecretHash = null;

    #[ORM\Column(length: 12, nullable: true)]
    #[Groups(['oauth2_client:read'])]
    private ?string $clientSecretPrefix = null;

    #[ORM\Column(length: 20)]
    #[Groups(['oauth2_client:read'])]
    private string $clientType = 'confidential';

    #[ORM\Column(type: Types::JSON)]
    #[Groups(['oauth2_client:read'])]
    private array $redirectUris = [];

    #[ORM\Column(type: Types::JSON)]
    #[Groups(['oauth2_client:read'])]
    private array $scopes = [];

    #[ORM\Column(length: 512, nullable: true)]
    #[Groups(['oauth2_client:read'])]
    private ?string $websiteUrl = null;

    #[ORM\Column(length: 512, nullable: true)]
    #[Groups(['oauth2_client:read'])]
    private ?string $logoUrl = null;

    #[ORM\Column]
    #[Groups(['oauth2_client:read'])]
    private bool $isActive = true;

    #[ORM\Column(nullable: true)]
    #[Groups(['oauth2_client:read'])]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column]
    #[Groups(['oauth2_client:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
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

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getClientId(): ?string
    {
        return $this->clientId;
    }

    public function setClientId(?string $clientId): static
    {
        $this->clientId = $clientId;

        return $this;
    }

    public function getClientSecretHash(): ?string
    {
        return $this->clientSecretHash;
    }

    public function setClientSecretHash(?string $clientSecretHash): static
    {
        $this->clientSecretHash = $clientSecretHash;

        return $this;
    }

    public function getClientSecretPrefix(): ?string
    {
        return $this->clientSecretPrefix;
    }

    public function setClientSecretPrefix(?string $clientSecretPrefix): static
    {
        $this->clientSecretPrefix = $clientSecretPrefix;

        return $this;
    }

    public function getClientType(): string
    {
        return $this->clientType;
    }

    public function setClientType(string $clientType): static
    {
        $this->clientType = $clientType;

        return $this;
    }

    public function isConfidential(): bool
    {
        return $this->clientType === 'confidential';
    }

    public function isPublic(): bool
    {
        return $this->clientType === 'public';
    }

    public function getRedirectUris(): array
    {
        return $this->redirectUris;
    }

    public function setRedirectUris(array $redirectUris): static
    {
        $this->redirectUris = $redirectUris;

        return $this;
    }

    public function hasRedirectUri(string $uri): bool
    {
        return in_array($uri, $this->redirectUris, true);
    }

    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function setScopes(array $scopes): static
    {
        $this->scopes = $scopes;

        return $this;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function getWebsiteUrl(): ?string
    {
        return $this->websiteUrl;
    }

    public function setWebsiteUrl(?string $websiteUrl): static
    {
        $this->websiteUrl = $websiteUrl;

        return $this;
    }

    public function getLogoUrl(): ?string
    {
        return $this->logoUrl;
    }

    public function setLogoUrl(?string $logoUrl): static
    {
        $this->logoUrl = $logoUrl;

        return $this;
    }

    public function getIsActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function setRevokedAt(?\DateTimeImmutable $revokedAt): static
    {
        $this->revokedAt = $revokedAt;

        return $this;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function isUsable(): bool
    {
        return $this->isActive && !$this->isRevoked();
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
