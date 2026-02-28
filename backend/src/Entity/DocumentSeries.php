<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Repository\DocumentSeriesRepository;
use Doctrine\ORM\Mapping as ORM;
use App\Doctrine\Type\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: DocumentSeriesRepository::class)]
#[ORM\Index(name: 'idx_docseries_company', columns: ['company_id'])]
#[ORM\UniqueConstraint(name: 'uniq_docseries_company_prefix', columns: ['company_id', 'prefix'])]
class DocumentSeries
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['docseries:list', 'docseries:detail', 'invoice:detail', 'proforma:detail', 'delivery_note:detail', 'receipt:detail'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Company $company = null;

    #[ORM\Column(length: 50)]
    #[Groups(['docseries:list', 'docseries:detail', 'invoice:detail', 'proforma:detail', 'delivery_note:detail', 'receipt:detail'])]
    private ?string $prefix = null;

    #[ORM\Column]
    #[Groups(['docseries:list', 'docseries:detail'])]
    private int $currentNumber = 0;

    #[ORM\Column(length: 20)]
    #[Groups(['docseries:list', 'docseries:detail'])]
    private string $type = 'invoice'; // invoice, credit_note, delivery_note, proforma

    #[ORM\Column]
    #[Groups(['docseries:list', 'docseries:detail'])]
    private bool $active = true;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['docseries:list', 'docseries:detail'])]
    private bool $isDefault = false;

    #[ORM\Column(length: 20, options: ['default' => 'manual'])]
    #[Groups(['docseries:list', 'docseries:detail'])]
    private string $source = 'manual';

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

    public function getPrefix(): ?string
    {
        return $this->prefix;
    }

    public function setPrefix(string $prefix): static
    {
        $this->prefix = $prefix;

        return $this;
    }

    public function getCurrentNumber(): int
    {
        return $this->currentNumber;
    }

    public function setCurrentNumber(int $currentNumber): static
    {
        $this->currentNumber = $currentNumber;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(bool $isDefault): static
    {
        $this->isDefault = $isDefault;

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * Virtual getter: formatted next invoice number (e.g. "FACT0043").
     */
    #[Groups(['docseries:list', 'docseries:detail', 'invoice:detail', 'proforma:detail', 'delivery_note:detail', 'receipt:detail'])]
    public function getNextNumber(): string
    {
        $next = $this->currentNumber + 1;

        return $this->prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function setSource(string $source): static
    {
        $this->source = $source;

        return $this;
    }
}
