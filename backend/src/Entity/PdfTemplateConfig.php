<?php

namespace App\Entity;

use App\Doctrine\Type\UuidType;
use App\Entity\Traits\AuditableTrait;
use App\Repository\PdfTemplateConfigRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PdfTemplateConfigRepository::class)]
class PdfTemplateConfig
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private ?Uuid $id = null;

    #[ORM\OneToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Company $company = null;

    #[ORM\Column(length: 50)]
    #[Groups(['pdf_config'])]
    private string $templateSlug = 'classic';

    #[ORM\Column(length: 7, nullable: true)]
    #[Groups(['pdf_config'])]
    private ?string $primaryColor = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['pdf_config'])]
    private ?string $fontFamily = null;

    #[ORM\Column]
    #[Groups(['pdf_config'])]
    private bool $showLogo = true;

    #[ORM\Column]
    #[Groups(['pdf_config'])]
    private bool $showBankInfo = true;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['pdf_config'])]
    private ?string $footerText = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['pdf_config'])]
    private ?string $customCss = null;

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

    public function getTemplateSlug(): string
    {
        return $this->templateSlug;
    }

    public function setTemplateSlug(string $templateSlug): static
    {
        $this->templateSlug = $templateSlug;

        return $this;
    }

    public function getPrimaryColor(): ?string
    {
        return $this->primaryColor;
    }

    public function setPrimaryColor(?string $primaryColor): static
    {
        $this->primaryColor = $primaryColor;

        return $this;
    }

    public function getFontFamily(): ?string
    {
        return $this->fontFamily;
    }

    public function setFontFamily(?string $fontFamily): static
    {
        $this->fontFamily = $fontFamily;

        return $this;
    }

    public function isShowLogo(): bool
    {
        return $this->showLogo;
    }

    public function setShowLogo(bool $showLogo): static
    {
        $this->showLogo = $showLogo;

        return $this;
    }

    public function isShowBankInfo(): bool
    {
        return $this->showBankInfo;
    }

    public function setShowBankInfo(bool $showBankInfo): static
    {
        $this->showBankInfo = $showBankInfo;

        return $this;
    }

    public function getFooterText(): ?string
    {
        return $this->footerText;
    }

    public function setFooterText(?string $footerText): static
    {
        $this->footerText = $footerText;

        return $this;
    }

    public function getCustomCss(): ?string
    {
        return $this->customCss;
    }

    public function setCustomCss(?string $customCss): static
    {
        $this->customCss = $customCss;

        return $this;
    }
}
