<?php

namespace App\Entity;

use App\Doctrine\Type\UuidType;
use App\Repository\TrialBalanceRowRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TrialBalanceRowRepository::class)]
#[ORM\Index(name: 'idx_trial_balance_row_balance', columns: ['trial_balance_id'])]
#[ORM\Index(name: 'idx_trial_balance_row_account', columns: ['trial_balance_id', 'account_code'])]
class TrialBalanceRow
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(inversedBy: 'rows', cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false)]
    private ?TrialBalance $trialBalance = null;

    #[ORM\Column(length: 20)]
    private ?string $accountCode = null;

    #[ORM\Column(length: 255)]
    private ?string $accountName = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $initialDebit = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $initialCredit = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $previousDebit = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $previousCredit = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $currentDebit = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $currentCredit = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $totalDebit = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $totalCredit = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $finalDebit = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $finalCredit = '0.00';

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getTrialBalance(): ?TrialBalance
    {
        return $this->trialBalance;
    }

    public function setTrialBalance(?TrialBalance $trialBalance): static
    {
        $this->trialBalance = $trialBalance;

        return $this;
    }

    public function getAccountCode(): ?string
    {
        return $this->accountCode;
    }

    public function setAccountCode(?string $accountCode): static
    {
        $this->accountCode = $accountCode;

        return $this;
    }

    public function getAccountName(): ?string
    {
        return $this->accountName;
    }

    public function setAccountName(?string $accountName): static
    {
        $this->accountName = $accountName;

        return $this;
    }

    public function getInitialDebit(): string
    {
        return $this->initialDebit;
    }

    public function setInitialDebit(string $initialDebit): static
    {
        $this->initialDebit = $initialDebit;

        return $this;
    }

    public function getInitialCredit(): string
    {
        return $this->initialCredit;
    }

    public function setInitialCredit(string $initialCredit): static
    {
        $this->initialCredit = $initialCredit;

        return $this;
    }

    public function getPreviousDebit(): string
    {
        return $this->previousDebit;
    }

    public function setPreviousDebit(string $previousDebit): static
    {
        $this->previousDebit = $previousDebit;

        return $this;
    }

    public function getPreviousCredit(): string
    {
        return $this->previousCredit;
    }

    public function setPreviousCredit(string $previousCredit): static
    {
        $this->previousCredit = $previousCredit;

        return $this;
    }

    public function getCurrentDebit(): string
    {
        return $this->currentDebit;
    }

    public function setCurrentDebit(string $currentDebit): static
    {
        $this->currentDebit = $currentDebit;

        return $this;
    }

    public function getCurrentCredit(): string
    {
        return $this->currentCredit;
    }

    public function setCurrentCredit(string $currentCredit): static
    {
        $this->currentCredit = $currentCredit;

        return $this;
    }

    public function getTotalDebit(): string
    {
        return $this->totalDebit;
    }

    public function setTotalDebit(string $totalDebit): static
    {
        $this->totalDebit = $totalDebit;

        return $this;
    }

    public function getTotalCredit(): string
    {
        return $this->totalCredit;
    }

    public function setTotalCredit(string $totalCredit): static
    {
        $this->totalCredit = $totalCredit;

        return $this;
    }

    public function getFinalDebit(): string
    {
        return $this->finalDebit;
    }

    public function setFinalDebit(string $finalDebit): static
    {
        $this->finalDebit = $finalDebit;

        return $this;
    }

    public function getFinalCredit(): string
    {
        return $this->finalCredit;
    }

    public function setFinalCredit(string $finalCredit): static
    {
        $this->finalCredit = $finalCredit;

        return $this;
    }
}
