<?php

namespace App\Entity;

use App\Doctrine\Type\UuidType;
use App\Enum\ShareTokenStatus;
use App\Repository\InvoiceShareTokenRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: InvoiceShareTokenRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(name: 'idx_share_token', columns: ['token'])]
#[ORM\Index(name: 'idx_share_invoice_status', columns: ['invoice_id', 'status'])]
class InvoiceShareToken
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private ?Uuid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Invoice $invoice = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Company $company = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?EmailLog $emailLog = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(length: 64, unique: true)]
    private ?string $token = null;

    #[ORM\Column(length: 20, enumType: ShareTokenStatus::class)]
    private ShareTokenStatus $status = ShareTokenStatus::ACTIVE;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastViewedAt = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $viewCount = 0;

    #[ORM\Column]
    private bool $paymentEnabled = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeSessionId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripePaymentIntentId = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->token = bin2hex(random_bytes(32));
    }

    #[ORM\PrePersist]
    public function prePersist(): void
    {
        if (!$this->createdAt) {
            $this->createdAt = new \DateTimeImmutable();
        }
        if (!$this->expiresAt) {
            $this->expiresAt = new \DateTimeImmutable('+30 days');
        }
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getInvoice(): ?Invoice
    {
        return $this->invoice;
    }

    public function setInvoice(?Invoice $invoice): static
    {
        $this->invoice = $invoice;

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

    public function getEmailLog(): ?EmailLog
    {
        return $this->emailLog;
    }

    public function setEmailLog(?EmailLog $emailLog): static
    {
        $this->emailLog = $emailLog;

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

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function getStatus(): ShareTokenStatus
    {
        return $this->status;
    }

    public function setStatus(ShareTokenStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getLastViewedAt(): ?\DateTimeImmutable
    {
        return $this->lastViewedAt;
    }

    public function getViewCount(): int
    {
        return $this->viewCount;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }

    public function isRevoked(): bool
    {
        return $this->status === ShareTokenStatus::REVOKED;
    }

    public function isValid(): bool
    {
        return $this->status === ShareTokenStatus::ACTIVE && !$this->isExpired();
    }

    public function revoke(): void
    {
        $this->status = ShareTokenStatus::REVOKED;
        $this->revokedAt = new \DateTimeImmutable();
    }

    public function recordView(): void
    {
        $this->viewCount++;
        $this->lastViewedAt = new \DateTimeImmutable();
    }

    public function isPaymentEnabled(): bool
    {
        return $this->paymentEnabled;
    }

    public function setPaymentEnabled(bool $paymentEnabled): static
    {
        $this->paymentEnabled = $paymentEnabled;

        return $this;
    }

    public function getStripeSessionId(): ?string
    {
        return $this->stripeSessionId;
    }

    public function setStripeSessionId(?string $stripeSessionId): static
    {
        $this->stripeSessionId = $stripeSessionId;

        return $this;
    }

    public function getStripePaymentIntentId(): ?string
    {
        return $this->stripePaymentIntentId;
    }

    public function setStripePaymentIntentId(?string $stripePaymentIntentId): static
    {
        $this->stripePaymentIntentId = $stripePaymentIntentId;

        return $this;
    }
}
