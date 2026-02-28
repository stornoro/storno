<?php

namespace App\Entity;

use App\Doctrine\Type\UuidType;
use App\Enum\EInvoiceProvider;
use App\Repository\CompanyEInvoiceConfigRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CompanyEInvoiceConfigRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_company_provider', columns: ['company_id', 'provider'])]
class CompanyEInvoiceConfig
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['einvoice_config:list', 'einvoice_config:detail'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Company $company = null;

    #[ORM\Column(length: 20, enumType: EInvoiceProvider::class)]
    #[Groups(['einvoice_config:list', 'einvoice_config:detail'])]
    private EInvoiceProvider $provider;

    #[ORM\Column]
    #[Groups(['einvoice_config:list', 'einvoice_config:detail'])]
    private bool $enabled = false;

    #[ORM\Column(nullable: true)]
    private ?array $config = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $encryptedConfig = null;

    #[ORM\Column]
    #[Groups(['einvoice_config:list'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    #[Groups(['einvoice_config:list'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getProvider(): EInvoiceProvider
    {
        return $this->provider;
    }

    public function setProvider(EInvoiceProvider $provider): static
    {
        $this->provider = $provider;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getConfig(): ?array
    {
        return $this->config;
    }

    public function setConfig(?array $config): static
    {
        $this->config = $config;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getEncryptedConfig(): ?string
    {
        return $this->encryptedConfig;
    }

    public function setEncryptedConfig(?string $encryptedConfig): static
    {
        $this->encryptedConfig = $encryptedConfig;
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
}
