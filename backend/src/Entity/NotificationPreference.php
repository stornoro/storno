<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Doctrine\Type\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'unique_user_event_type', columns: ['user_id', 'event_type'])]
class NotificationPreference
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private ?Uuid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 100)]
    private ?string $eventType = null;

    #[ORM\Column]
    private bool $emailEnabled = false;

    #[ORM\Column]
    private bool $inAppEnabled = true;

    #[ORM\Column]
    private bool $pushEnabled = false;

    #[ORM\Column]
    private bool $telegramEnabled = false;

    #[ORM\Column]
    private bool $whatsappEnabled = false;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
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

    public function getEventType(): ?string
    {
        return $this->eventType;
    }

    public function setEventType(string $eventType): static
    {
        $this->eventType = $eventType;

        return $this;
    }

    public function isEmailEnabled(): bool
    {
        return $this->emailEnabled;
    }

    public function setEmailEnabled(bool $emailEnabled): static
    {
        $this->emailEnabled = $emailEnabled;

        return $this;
    }

    public function isInAppEnabled(): bool
    {
        return $this->inAppEnabled;
    }

    public function setInAppEnabled(bool $inAppEnabled): static
    {
        $this->inAppEnabled = $inAppEnabled;

        return $this;
    }

    public function isPushEnabled(): bool
    {
        return $this->pushEnabled;
    }

    public function setPushEnabled(bool $pushEnabled): static
    {
        $this->pushEnabled = $pushEnabled;

        return $this;
    }

    public function isTelegramEnabled(): bool
    {
        return $this->telegramEnabled;
    }

    public function setTelegramEnabled(bool $telegramEnabled): static
    {
        $this->telegramEnabled = $telegramEnabled;

        return $this;
    }

    public function isWhatsappEnabled(): bool
    {
        return $this->whatsappEnabled;
    }

    public function setWhatsappEnabled(bool $whatsappEnabled): static
    {
        $this->whatsappEnabled = $whatsappEnabled;

        return $this;
    }
}
