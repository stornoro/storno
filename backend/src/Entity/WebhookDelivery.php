<?php

namespace App\Entity;

use App\Enum\WebhookDeliveryStatus;
use App\Repository\WebhookDeliveryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Doctrine\Type\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: WebhookDeliveryRepository::class)]
#[ORM\Index(name: 'idx_webhook_delivery_endpoint_triggered', columns: ['endpoint_id', 'triggered_at'])]
#[ORM\Index(name: 'idx_webhook_delivery_status_retry', columns: ['status', 'next_retry_at'])]
class WebhookDelivery
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(inversedBy: 'deliveries')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?WebhookEndpoint $endpoint = null;

    #[ORM\Column(length: 50)]
    private ?string $eventType = null;

    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $responseStatusCode = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $responseBody = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $durationMs = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $attempt = 1;

    #[ORM\Column(length: 20, enumType: WebhookDeliveryStatus::class)]
    private WebhookDeliveryStatus $status = WebhookDeliveryStatus::PENDING;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $triggeredAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $nextRetryAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->triggeredAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getEndpoint(): ?WebhookEndpoint
    {
        return $this->endpoint;
    }

    public function setEndpoint(?WebhookEndpoint $endpoint): static
    {
        $this->endpoint = $endpoint;

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

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function setPayload(array $payload): static
    {
        $this->payload = $payload;

        return $this;
    }

    public function getResponseStatusCode(): ?int
    {
        return $this->responseStatusCode;
    }

    public function setResponseStatusCode(?int $responseStatusCode): static
    {
        $this->responseStatusCode = $responseStatusCode;

        return $this;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }

    public function setResponseBody(?string $responseBody): static
    {
        // Truncate to 10KB
        if ($responseBody !== null && strlen($responseBody) > 10240) {
            $responseBody = substr($responseBody, 0, 10240) . '... [truncated]';
        }

        $this->responseBody = $responseBody;

        return $this;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function setDurationMs(?int $durationMs): static
    {
        $this->durationMs = $durationMs;

        return $this;
    }

    public function getAttempt(): int
    {
        return $this->attempt;
    }

    public function setAttempt(int $attempt): static
    {
        $this->attempt = $attempt;

        return $this;
    }

    public function getStatus(): WebhookDeliveryStatus
    {
        return $this->status;
    }

    public function setStatus(WebhookDeliveryStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function getTriggeredAt(): ?\DateTimeImmutable
    {
        return $this->triggeredAt;
    }

    public function setTriggeredAt(\DateTimeImmutable $triggeredAt): static
    {
        $this->triggeredAt = $triggeredAt;

        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): static
    {
        $this->completedAt = $completedAt;

        return $this;
    }

    public function getNextRetryAt(): ?\DateTimeImmutable
    {
        return $this->nextRetryAt;
    }

    public function setNextRetryAt(?\DateTimeImmutable $nextRetryAt): static
    {
        $this->nextRetryAt = $nextRetryAt;

        return $this;
    }
}
