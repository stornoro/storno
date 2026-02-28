<?php

namespace App\Entity;

use App\Doctrine\Type\UuidType;
use App\Enum\InvitationStatus;
use App\Enum\OrganizationRole;
use App\Repository\OrganizationInvitationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: OrganizationInvitationRepository::class)]
#[ORM\Index(columns: ['token'], name: 'idx_invitation_token')]
#[ORM\Index(columns: ['email', 'status'], name: 'idx_invitation_email_status')]
#[ORM\HasLifecycleCallbacks]
class OrganizationInvitation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private ?Uuid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Organization $organization = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $invitedBy = null;

    #[ORM\Column(length: 255)]
    private ?string $email = null;

    #[ORM\Column(length: 20, enumType: OrganizationRole::class)]
    private OrganizationRole $role = OrganizationRole::EMPLOYEE;

    #[ORM\Column(length: 64, unique: true)]
    private ?string $token = null;

    #[ORM\Column(length: 20, enumType: InvitationStatus::class)]
    private InvitationStatus $status = InvitationStatus::PENDING;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    #[ORM\Column(type: Types::JSON)]
    private array $allowedCompanyIds = [];

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->token = bin2hex(random_bytes(32));
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = new \DateTimeImmutable('+7 days');
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

    public function getInvitedBy(): ?User
    {
        return $this->invitedBy;
    }

    public function setInvitedBy(?User $invitedBy): static
    {
        $this->invitedBy = $invitedBy;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = mb_strtolower(trim($email));

        return $this;
    }

    public function getRole(): OrganizationRole
    {
        return $this->role;
    }

    public function setRole(OrganizationRole $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function getStatus(): InvitationStatus
    {
        return $this->status;
    }

    public function setStatus(InvitationStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }

    public function isPending(): bool
    {
        return $this->status === InvitationStatus::PENDING && !$this->isExpired();
    }

    public function isValid(): bool
    {
        return $this->isPending();
    }

    public function accept(): static
    {
        $this->status = InvitationStatus::ACCEPTED;
        $this->acceptedAt = new \DateTimeImmutable();

        return $this;
    }

    public function cancel(): static
    {
        $this->status = InvitationStatus::CANCELLED;

        return $this;
    }

    public function resetExpiry(): static
    {
        $this->expiresAt = new \DateTimeImmutable('+7 days');

        return $this;
    }

    public function getAllowedCompanyIds(): array
    {
        return $this->allowedCompanyIds;
    }

    public function setAllowedCompanyIds(array $allowedCompanyIds): static
    {
        $this->allowedCompanyIds = $allowedCompanyIds;

        return $this;
    }
}
