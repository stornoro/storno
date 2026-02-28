<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Repository\StripeConnectAccountRepository;
use Doctrine\ORM\Mapping as ORM;
use App\Doctrine\Type\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: StripeConnectAccountRepository::class)]
class StripeConnectAccount
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private ?Uuid $id = null;

    #[ORM\OneToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Company $company = null;

    #[ORM\Column(length: 255)]
    private string $stripeAccountId;

    #[ORM\Column]
    private bool $chargesEnabled = false;

    #[ORM\Column]
    private bool $payoutsEnabled = false;

    #[ORM\Column]
    private bool $detailsSubmitted = false;

    #[ORM\Column]
    private bool $onboardingComplete = false;

    #[ORM\Column]
    private bool $paymentEnabledByDefault = true;

    #[ORM\Column]
    private bool $allowPartialPayments = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $successMessage = null;

    #[ORM\Column]
    private bool $notifyOnPayment = true;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
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

    public function getStripeAccountId(): string
    {
        return $this->stripeAccountId;
    }

    public function setStripeAccountId(string $stripeAccountId): static
    {
        $this->stripeAccountId = $stripeAccountId;

        return $this;
    }

    public function isChargesEnabled(): bool
    {
        return $this->chargesEnabled;
    }

    public function setChargesEnabled(bool $chargesEnabled): static
    {
        $this->chargesEnabled = $chargesEnabled;

        return $this;
    }

    public function isPayoutsEnabled(): bool
    {
        return $this->payoutsEnabled;
    }

    public function setPayoutsEnabled(bool $payoutsEnabled): static
    {
        $this->payoutsEnabled = $payoutsEnabled;

        return $this;
    }

    public function isDetailsSubmitted(): bool
    {
        return $this->detailsSubmitted;
    }

    public function setDetailsSubmitted(bool $detailsSubmitted): static
    {
        $this->detailsSubmitted = $detailsSubmitted;

        return $this;
    }

    public function isOnboardingComplete(): bool
    {
        return $this->onboardingComplete;
    }

    public function setOnboardingComplete(bool $onboardingComplete): static
    {
        $this->onboardingComplete = $onboardingComplete;

        return $this;
    }

    public function isPaymentEnabledByDefault(): bool
    {
        return $this->paymentEnabledByDefault;
    }

    public function setPaymentEnabledByDefault(bool $paymentEnabledByDefault): static
    {
        $this->paymentEnabledByDefault = $paymentEnabledByDefault;

        return $this;
    }

    public function isAllowPartialPayments(): bool
    {
        return $this->allowPartialPayments;
    }

    public function setAllowPartialPayments(bool $allowPartialPayments): static
    {
        $this->allowPartialPayments = $allowPartialPayments;

        return $this;
    }

    public function getSuccessMessage(): ?string
    {
        return $this->successMessage;
    }

    public function setSuccessMessage(?string $successMessage): static
    {
        $this->successMessage = $successMessage;

        return $this;
    }

    public function isNotifyOnPayment(): bool
    {
        return $this->notifyOnPayment;
    }

    public function setNotifyOnPayment(bool $notifyOnPayment): static
    {
        $this->notifyOnPayment = $notifyOnPayment;

        return $this;
    }

    public function isFullyActive(): bool
    {
        return $this->chargesEnabled && $this->payoutsEnabled && $this->detailsSubmitted;
    }
}
