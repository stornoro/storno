<?php

namespace App\Entity;

use App\Doctrine\Type\UuidType;
use App\Repository\StripeAppDeviceCodeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: StripeAppDeviceCodeRepository::class)]
#[ORM\Index(name: 'idx_stripe_app_device_code', columns: ['device_code'])]
#[ORM\Index(name: 'idx_stripe_app_user_code', columns: ['user_code'])]
class StripeAppDeviceCode
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DENIED = 'denied';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private ?Uuid $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $deviceCode;

    #[ORM\Column(length: 8, unique: true)]
    private string $userCode;

    #[ORM\Column(length: 255)]
    private string $stripeAccountId;

    #[ORM\Column(length: 16, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $user = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Company $company = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Organization $organization = null;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastPolledAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getDeviceCode(): string
    {
        return $this->deviceCode;
    }

    public function setDeviceCode(string $deviceCode): static
    {
        $this->deviceCode = $deviceCode;

        return $this;
    }

    public function getUserCode(): string
    {
        return $this->userCode;
    }

    public function setUserCode(string $userCode): static
    {
        $this->userCode = $userCode;

        return $this;
    }

    public function getStripeAccountId(): string
    {
        return $this->stripeAccountId;
    }

    public function setStripeAccountId(string $stripeAccountId): static
    {
        $this->stripeAccountId = $stripeAccountId;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    public function setCompany(?Company $company): static
    {
        $this->company = $company;

        return $this;
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

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getApprovedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function setApprovedAt(?\DateTimeImmutable $approvedAt): static
    {
        $this->approvedAt = $approvedAt;

        return $this;
    }

    public function getLastPolledAt(): ?\DateTimeImmutable
    {
        return $this->lastPolledAt;
    }

    public function setLastPolledAt(?\DateTimeImmutable $lastPolledAt): static
    {
        $this->lastPolledAt = $lastPolledAt;

        return $this;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING && !$this->isExpired();
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isDenied(): bool
    {
        return $this->status === self::STATUS_DENIED;
    }

    public static function generateDeviceCode(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function generateUserCode(): string
    {
        // Avoid ambiguous characters: 0/O, 1/I/L
        $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $code;
    }
}
