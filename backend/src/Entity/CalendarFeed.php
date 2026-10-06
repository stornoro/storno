<?php

namespace App\Entity;

use App\Doctrine\Type\UuidType;
use App\Repository\CalendarFeedRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A calendar subscription (iCalendar feed) of one member of an organization: the expiries
 * (vehicle documents, contracts, certificates) and the fiscal deadlines of the companies the
 * member can see, read by Apple Calendar, Google Calendar or Outlook from a secret link.
 *
 * No secret is stored: the link carries an HMAC of the feed id and `version` keyed with the app
 * secret, so it can be shown again at any time. Regenerating bumps `version` and every older link
 * stops working; deleting the row turns the subscription off.
 */
#[ORM\Entity(repositoryClass: CalendarFeedRepository::class)]
#[ORM\Table(name: 'calendar_feed')]
#[ORM\UniqueConstraint(name: 'uniq_calendar_feed_member', columns: ['user_id', 'organization_id'])]
class CalendarFeed
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Organization $organization = null;

    #[ORM\Column(options: ['default' => 1])]
    private int $version = 1;

    #[ORM\Column(options: ['default' => true])]
    private bool $includeExpiries = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $includeFiscal = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastFetchedAt = null;

    public function __construct(User $user, Organization $organization)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->organization = $organization;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getOrganization(): ?Organization
    {
        return $this->organization;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    /** Invalidates every link handed out so far. */
    public function rotate(): static
    {
        $this->version++;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function includesExpiries(): bool
    {
        return $this->includeExpiries;
    }

    public function setIncludeExpiries(bool $include): static
    {
        $this->includeExpiries = $include;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function includesFiscal(): bool
    {
        return $this->includeFiscal;
    }

    public function setIncludeFiscal(bool $include): static
    {
        $this->includeFiscal = $include;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getLastFetchedAt(): ?\DateTimeImmutable
    {
        return $this->lastFetchedAt;
    }

    public function markFetched(\DateTimeImmutable $at): static
    {
        $this->lastFetchedAt = $at;

        return $this;
    }
}
