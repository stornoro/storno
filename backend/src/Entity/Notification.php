<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Repository\NotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Doctrine\Type\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: NotificationRepository::class)]
class Notification
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(inversedBy: 'notifications')]
    #[Ignore]
    private ?User $user = null;

    #[ORM\Column(length: 100)]
    private ?string $type = null;

    #[ORM\Column(length: 255)]
    private ?string $channel = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(length: 255)]
    private ?string $message = null;

    #[ORM\Column(name: '`from`', length: 255, nullable: true)]
    private ?string $from = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $link = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column]
    private bool $isRead = false;

    #[ORM\Column(type: Types::JSON)]
    private array $data = [];

    #[ORM\Column]
    private bool $emailSent = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $pushAttempted = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $pushSentAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $pushError = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $pushSkippedReason = null;

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

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getChannel(): ?string
    {
        return $this->channel;
    }

    public function setChannel(string $channel): static
    {
        $this->channel = $channel;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(string $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function getFrom(): ?string
    {
        return $this->from;
    }

    public function setFrom(?string $from): static
    {
        $this->from = $from;

        return $this;
    }

    public function getLink(): ?string
    {
        return $this->link;
    }

    public function setLink(?string $link): static
    {
        $this->link = $link;

        return $this;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(\DateTimeImmutable $sentAt): static
    {
        $this->sentAt = $sentAt;

        return $this;
    }

    public function isRead(): bool
    {
        return $this->isRead;
    }

    public function setIsRead(bool $isRead): static
    {
        $this->isRead = $isRead;

        return $this;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function isEmailSent(): bool
    {
        return $this->emailSent;
    }

    public function setEmailSent(bool $emailSent): static
    {
        $this->emailSent = $emailSent;

        return $this;
    }

    public function isPushAttempted(): bool
    {
        return $this->pushAttempted;
    }

    public function setPushAttempted(bool $pushAttempted): static
    {
        $this->pushAttempted = $pushAttempted;

        return $this;
    }

    public function getPushSentAt(): ?\DateTimeImmutable
    {
        return $this->pushSentAt;
    }

    public function setPushSentAt(?\DateTimeImmutable $pushSentAt): static
    {
        $this->pushSentAt = $pushSentAt;

        return $this;
    }

    public function getPushError(): ?string
    {
        return $this->pushError;
    }

    public function setPushError(?string $pushError): static
    {
        $this->pushError = $pushError;

        return $this;
    }

    public function getPushSkippedReason(): ?string
    {
        return $this->pushSkippedReason;
    }

    public function setPushSkippedReason(?string $pushSkippedReason): static
    {
        $this->pushSkippedReason = $pushSkippedReason;

        return $this;
    }
}
