<?php

namespace App\Entity;

use App\Doctrine\Type\UuidType;
use App\Entity\Traits\AuditableTrait;
use App\Repository\WhiteLabelConfigRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: WhiteLabelConfigRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_white_label_custom_domain', columns: ['custom_domain'])]
class WhiteLabelConfig
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private ?Uuid $id = null;

    #[ORM\OneToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Organization $organization = null;

    #[ORM\Column]
    #[Groups(['white_label'])]
    private bool $enabled = false;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['white_label'])]
    private ?string $appName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoPath = null;

    #[ORM\Column(length: 7, nullable: true)]
    #[Groups(['white_label'])]
    private ?string $primaryColor = null;

    #[ORM\Column]
    #[Groups(['white_label'])]
    private bool $removeBranding = false;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['white_label'])]
    private ?string $customDomain = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $customDomainToken = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['white_label'])]
    private ?\DateTimeImmutable $customDomainVerifiedAt = null;

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

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getAppName(): ?string
    {
        return $this->appName;
    }

    public function setAppName(?string $appName): static
    {
        $this->appName = $appName;

        return $this;
    }

    public function getLogoPath(): ?string
    {
        return $this->logoPath;
    }

    public function setLogoPath(?string $logoPath): static
    {
        $this->logoPath = $logoPath;

        return $this;
    }

    public function getPrimaryColor(): ?string
    {
        return $this->primaryColor;
    }

    public function setPrimaryColor(?string $primaryColor): static
    {
        $this->primaryColor = $primaryColor;

        return $this;
    }

    public function isRemoveBranding(): bool
    {
        return $this->removeBranding;
    }

    public function setRemoveBranding(bool $removeBranding): static
    {
        $this->removeBranding = $removeBranding;

        return $this;
    }

    public function getCustomDomain(): ?string
    {
        return $this->customDomain;
    }

    public function setCustomDomain(?string $customDomain): static
    {
        $this->customDomain = $customDomain;

        return $this;
    }

    public function getCustomDomainToken(): ?string
    {
        return $this->customDomainToken;
    }

    public function setCustomDomainToken(?string $customDomainToken): static
    {
        $this->customDomainToken = $customDomainToken;

        return $this;
    }

    public function getCustomDomainVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->customDomainVerifiedAt;
    }

    public function setCustomDomainVerifiedAt(?\DateTimeImmutable $customDomainVerifiedAt): static
    {
        $this->customDomainVerifiedAt = $customDomainVerifiedAt;

        return $this;
    }

    public function isCustomDomainVerified(): bool
    {
        return $this->customDomainVerifiedAt !== null;
    }
}
