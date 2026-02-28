<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Repository\WebhookEndpointRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Doctrine\Type\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: WebhookEndpointRepository::class)]
#[ORM\Index(name: 'idx_webhook_endpoint_company', columns: ['company_id'])]
class WebhookEndpoint
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private ?Uuid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Company $company = null;

    #[ORM\Column(length: 2048)]
    private ?string $url = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::JSON)]
    private array $events = [];

    #[ORM\Column(length: 255)]
    private ?string $secret = null;

    #[ORM\Column]
    private bool $isActive = true;

    /**
     * @var Collection<int, WebhookDelivery>
     */
    #[ORM\OneToMany(mappedBy: 'endpoint', targetEntity: WebhookDelivery::class, cascade: ['remove'])]
    #[ORM\OrderBy(['triggeredAt' => 'DESC'])]
    private Collection $deliveries;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->secret = bin2hex(random_bytes(32));
        $this->deliveries = new ArrayCollection();
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

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;

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

    public function getEvents(): array
    {
        return $this->events;
    }

    public function setEvents(array $events): static
    {
        $this->events = $events;

        return $this;
    }

    public function getSecret(): ?string
    {
        return $this->secret;
    }

    public function setSecret(string $secret): static
    {
        $this->secret = $secret;

        return $this;
    }

    public function regenerateSecret(): string
    {
        $this->secret = bin2hex(random_bytes(32));

        return $this->secret;
    }

    public function getMaskedSecret(): string
    {
        if ($this->secret === null) {
            return '';
        }

        $last8 = substr($this->secret, -8);

        return '***' . $last8;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function supportsEvent(string $eventType): bool
    {
        return in_array($eventType, $this->events, true);
    }

    /**
     * @return Collection<int, WebhookDelivery>
     */
    public function getDeliveries(): Collection
    {
        return $this->deliveries;
    }
}
