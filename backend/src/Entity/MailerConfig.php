<?php

namespace App\Entity;

use App\Doctrine\Type\UuidType;
use App\Entity\Traits\AuditableTrait;
use App\Repository\MailerConfigRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MailerConfigRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_mailer_config_organization', columns: ['organization_id'])]
class MailerConfig
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private ?Uuid $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Organization $organization = null;

    #[ORM\Column]
    #[Groups(['mailer:read'])]
    private bool $enabled = false;

    #[ORM\Column(length: 255)]
    #[Groups(['mailer:read'])]
    private string $host = '';

    #[ORM\Column]
    #[Groups(['mailer:read'])]
    private int $port = 587;

    #[ORM\Column(length: 10)]
    #[Groups(['mailer:read'])]
    private string $encryption = 'tls';

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['mailer:read'])]
    private ?string $username = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $encryptedCredentials = '';

    #[ORM\Column(length: 255)]
    #[Groups(['mailer:read'])]
    private string $fromAddress = '';

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['mailer:read'])]
    private ?string $fromName = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['mailer:read'])]
    private ?\DateTimeImmutable $lastTestedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
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

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function setHost(string $host): static
    {
        $this->host = $host;

        return $this;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function setPort(int $port): static
    {
        $this->port = $port;

        return $this;
    }

    public function getEncryption(): string
    {
        return $this->encryption;
    }

    public function setEncryption(string $encryption): static
    {
        $this->encryption = $encryption;

        return $this;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(?string $username): static
    {
        $this->username = $username;

        return $this;
    }

    public function getEncryptedCredentials(): string
    {
        return $this->encryptedCredentials;
    }

    public function setEncryptedCredentials(string $encryptedCredentials): static
    {
        $this->encryptedCredentials = $encryptedCredentials;

        return $this;
    }

    public function getFromAddress(): string
    {
        return $this->fromAddress;
    }

    public function setFromAddress(string $fromAddress): static
    {
        $this->fromAddress = $fromAddress;

        return $this;
    }

    public function getFromName(): ?string
    {
        return $this->fromName;
    }

    public function setFromName(?string $fromName): static
    {
        $this->fromName = $fromName;

        return $this;
    }

    public function getLastTestedAt(): ?\DateTimeImmutable
    {
        return $this->lastTestedAt;
    }

    public function setLastTestedAt(?\DateTimeImmutable $lastTestedAt): static
    {
        $this->lastTestedAt = $lastTestedAt;

        return $this;
    }
}
