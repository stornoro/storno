<?php

namespace App\Entity;

use App\Doctrine\Type\UuidType;
use App\Entity\Traits\AuditableTrait;
use App\Entity\Traits\SoftDeletableTrait;
use App\Repository\TrialBalanceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TrialBalanceRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_trial_balance_company_year_month', columns: ['company_id', 'year', 'month'])]
#[ORM\Index(name: 'idx_trial_balance_company_status', columns: ['company_id', 'status'])]
class TrialBalance
{
    use AuditableTrait;
    use SoftDeletableTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private ?Uuid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Company $company = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $year = 0;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $month = 0;

    #[ORM\Column(length: 255)]
    private ?string $originalFilename = null;

    #[ORM\Column(length: 500)]
    private ?string $storagePath = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $contentHash = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $sourceSoftware = null;

    /**
     * 'pending', 'processing', 'completed', 'failed'
     */
    #[ORM\Column(length: 20)]
    private string $status = 'pending';

    #[ORM\Column]
    private int $totalAccounts = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    #[ORM\OneToMany(mappedBy: 'trialBalance', targetEntity: TrialBalanceRow::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $rows;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->rows = new ArrayCollection();
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

    public function getYear(): int
    {
        return $this->year;
    }

    public function setYear(int $year): static
    {
        $this->year = $year;

        return $this;
    }

    public function getMonth(): int
    {
        return $this->month;
    }

    public function setMonth(int $month): static
    {
        $this->month = $month;

        return $this;
    }

    public function getOriginalFilename(): ?string
    {
        return $this->originalFilename;
    }

    public function setOriginalFilename(?string $originalFilename): static
    {
        $this->originalFilename = $originalFilename;

        return $this;
    }

    public function getStoragePath(): ?string
    {
        return $this->storagePath;
    }

    public function setStoragePath(?string $storagePath): static
    {
        $this->storagePath = $storagePath;

        return $this;
    }

    public function getContentHash(): ?string
    {
        return $this->contentHash;
    }

    public function setContentHash(?string $contentHash): static
    {
        $this->contentHash = $contentHash;

        return $this;
    }

    public function getSourceSoftware(): ?string
    {
        return $this->sourceSoftware;
    }

    public function setSourceSoftware(?string $sourceSoftware): static
    {
        $this->sourceSoftware = $sourceSoftware;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getTotalAccounts(): int
    {
        return $this->totalAccounts;
    }

    public function setTotalAccounts(int $totalAccounts): static
    {
        $this->totalAccounts = $totalAccounts;

        return $this;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): static
    {
        $this->error = $error;

        return $this;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function setProcessedAt(?\DateTimeImmutable $processedAt): static
    {
        $this->processedAt = $processedAt;

        return $this;
    }

    /**
     * @return Collection<int, TrialBalanceRow>
     */
    public function getRows(): Collection
    {
        return $this->rows;
    }

    public function addRow(TrialBalanceRow $row): static
    {
        if (!$this->rows->contains($row)) {
            $this->rows->add($row);
            $row->setTrialBalance($this);
        }

        return $this;
    }

    public function removeRow(TrialBalanceRow $row): static
    {
        if ($this->rows->removeElement($row)) {
            if ($row->getTrialBalance() === $this) {
                $row->setTrialBalance(null);
            }
        }

        return $this;
    }
}
