<?php

namespace App\Entity;

use App\Enum\EmailEventType;
use App\Repository\EmailEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Doctrine\Type\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: EmailEventRepository::class)]
#[ORM\Index(name: 'idx_emailevent_log_ts', columns: ['email_log_id', 'timestamp'])]
class EmailEvent
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['email_event:list'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(inversedBy: 'events')]
    #[ORM\JoinColumn(nullable: false)]
    private ?EmailLog $emailLog = null;

    #[ORM\Column(length: 20, enumType: EmailEventType::class)]
    #[Groups(['email_event:list'])]
    private EmailEventType $eventType;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['email_event:list'])]
    private ?string $bounceType = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['email_event:list'])]
    private ?string $bounceSubType = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['email_event:list'])]
    private \DateTimeImmutable $timestamp;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['email_event:list'])]
    private ?array $recipients = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['email_event:list'])]
    private ?string $userAgent = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['email_event:list'])]
    private ?string $eventDetail = null;

    #[ORM\Column(length: 2000, nullable: true)]
    #[Groups(['email_event:list'])]
    private ?string $linkClicked = null;

    #[ORM\Column(type: Types::JSON)]
    private array $rawData = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['email_event:list'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
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

    public function getEventType(): EmailEventType
    {
        return $this->eventType;
    }

    public function setEventType(EmailEventType $eventType): static
    {
        $this->eventType = $eventType;

        return $this;
    }

    public function getBounceType(): ?string
    {
        return $this->bounceType;
    }

    public function setBounceType(?string $bounceType): static
    {
        $this->bounceType = $bounceType;

        return $this;
    }

    public function getBounceSubType(): ?string
    {
        return $this->bounceSubType;
    }

    public function setBounceSubType(?string $bounceSubType): static
    {
        $this->bounceSubType = $bounceSubType;

        return $this;
    }

    public function getTimestamp(): \DateTimeImmutable
    {
        return $this->timestamp;
    }

    public function setTimestamp(\DateTimeImmutable $timestamp): static
    {
        $this->timestamp = $timestamp;

        return $this;
    }

    public function getRecipients(): ?array
    {
        return $this->recipients;
    }

    public function setRecipients(?array $recipients): static
    {
        $this->recipients = $recipients;

        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        $this->userAgent = $userAgent;

        return $this;
    }

    public function getEventDetail(): ?string
    {
        return $this->eventDetail;
    }

    public function setEventDetail(?string $eventDetail): static
    {
        $this->eventDetail = $eventDetail;

        return $this;
    }

    public function getLinkClicked(): ?string
    {
        return $this->linkClicked;
    }

    public function setLinkClicked(?string $linkClicked): static
    {
        $this->linkClicked = $linkClicked;

        return $this;
    }

    public function getRawData(): array
    {
        return $this->rawData;
    }

    public function setRawData(array $rawData): static
    {
        $this->rawData = $rawData;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
