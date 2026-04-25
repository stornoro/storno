<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Repository\BankAccountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Doctrine\Type\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: BankAccountRepository::class)]
#[ORM\Index(name: 'idx_bankaccount_company', columns: ['company_id'])]
class BankAccount
{
    use AuditableTrait;

    public const TYPE_BANK = 'bank';
    public const TYPE_CASH = 'cash';
    public const TYPES = [self::TYPE_BANK, self::TYPE_CASH];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['bankaccount:list', 'bankaccount:detail'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Company $company = null;

    #[ORM\Column(length: 34, nullable: true)]
    #[Groups(['bankaccount:list', 'bankaccount:detail'])]
    private ?string $iban = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['bankaccount:list', 'bankaccount:detail'])]
    private ?string $bankName = null;

    #[ORM\Column(length: 3)]
    #[Groups(['bankaccount:list', 'bankaccount:detail'])]
    private string $currency = 'RON';

    #[ORM\Column]
    #[Groups(['bankaccount:list', 'bankaccount:detail'])]
    private bool $isDefault = false;

    #[ORM\Column]
    #[Groups(['bankaccount:list', 'bankaccount:detail'])]
    private bool $showOnInvoice = false;

    #[ORM\Column(length: 20)]
    #[Groups(['bankaccount:detail'])]
    private string $source = 'manual';

    #[ORM\Column(length: 10, options: ['default' => self::TYPE_BANK])]
    #[Groups(['bankaccount:list', 'bankaccount:detail'])]
    private string $type = self::TYPE_BANK;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2, nullable: true)]
    #[Groups(['bankaccount:list', 'bankaccount:detail'])]
    private ?string $openingBalance = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    #[Groups(['bankaccount:list', 'bankaccount:detail'])]
    private ?\DateTimeImmutable $openingBalanceDate = null;

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

    public function getIban(): ?string
    {
        return $this->iban;
    }

    public function setIban(?string $iban): static
    {
        $this->iban = $iban === null ? null : strtoupper(preg_replace('/\s+/', '', $iban));

        return $this;
    }

    public function getBankName(): ?string
    {
        return $this->bankName;
    }

    public function setBankName(?string $bankName): static
    {
        $this->bankName = $bankName;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = $currency;

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

    public function isShowOnInvoice(): bool
    {
        return $this->showOnInvoice;
    }

    public function setShowOnInvoice(bool $showOnInvoice): static
    {
        $this->showOnInvoice = $showOnInvoice;

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;

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

    public function isCash(): bool
    {
        return $this->type === self::TYPE_CASH;
    }

    public function getOpeningBalance(): ?string
    {
        return $this->openingBalance;
    }

    public function setOpeningBalance(?string $openingBalance): static
    {
        $this->openingBalance = $openingBalance;

        return $this;
    }

    public function getOpeningBalanceDate(): ?\DateTimeImmutable
    {
        return $this->openingBalanceDate;
    }

    public function setOpeningBalanceDate(?\DateTimeImmutable $openingBalanceDate): static
    {
        $this->openingBalanceDate = $openingBalanceDate;

        return $this;
    }
}
