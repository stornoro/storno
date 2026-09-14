<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Type\UuidType;
use App\Repository\AnafFormVersionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One row per ANAF form in DUKIntegrator's manifest (versiuni.xml): the validator (J) and PDF (P)
 * versions ANAF currently publishes, and when they last changed. Read daily so a form that
 * changed at ANAF is known before a filing gets rejected for the old version.
 */
#[ORM\Entity(repositoryClass: AnafFormVersionRepository::class)]
#[ORM\Table(name: 'anaf_form_version')]
#[ORM\UniqueConstraint(name: 'uniq_anaf_form_version_form', columns: ['form'])]
class AnafFormVersion
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private ?Uuid $id = null;

    /** Form code as in the manifest: D300, D112, S1001… */
    #[ORM\Column(length: 20)]
    private string $form;

    #[ORM\Column(length: 40)]
    private string $versionJ = '';

    #[ORM\Column(length: 40)]
    private string $versionP = '';

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $previousJ = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $previousP = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $validatorUrl = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $pdfUrl = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $historyUrl = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $firstSeenAt;

    /** When the version last changed as seen by Storno (null while only ever seen once). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $changedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $checkedAt;

    public function __construct(string $form)
    {
        $this->id = Uuid::v7();
        $this->form = $form;
        $this->firstSeenAt = new \DateTimeImmutable();
        $this->checkedAt = $this->firstSeenAt;
    }

    public function getId(): ?Uuid { return $this->id; }
    public function getForm(): string { return $this->form; }
    public function getVersionJ(): string { return $this->versionJ; }
    public function getVersionP(): string { return $this->versionP; }
    public function getPreviousJ(): ?string { return $this->previousJ; }
    public function getPreviousP(): ?string { return $this->previousP; }
    public function getValidatorUrl(): ?string { return $this->validatorUrl; }
    public function getPdfUrl(): ?string { return $this->pdfUrl; }
    public function getHistoryUrl(): ?string { return $this->historyUrl; }
    public function getFirstSeenAt(): \DateTimeImmutable { return $this->firstSeenAt; }
    public function getChangedAt(): ?\DateTimeImmutable { return $this->changedAt; }
    public function getCheckedAt(): \DateTimeImmutable { return $this->checkedAt; }

    /** Apply what the manifest says now; returns true when a version moved. */
    public function observe(string $versionJ, string $versionP, ?string $validatorUrl, ?string $pdfUrl, ?string $historyUrl): bool
    {
        $now = new \DateTimeImmutable();
        $this->checkedAt = $now;
        $this->validatorUrl = $validatorUrl;
        $this->pdfUrl = $pdfUrl;
        $this->historyUrl = $historyUrl;
        $changed = false;
        if ($this->versionJ !== '' && $this->versionJ !== $versionJ) {
            $this->previousJ = $this->versionJ;
            $changed = true;
        }
        if ($this->versionP !== '' && $this->versionP !== $versionP) {
            $this->previousP = $this->versionP;
            $changed = true;
        }
        $this->versionJ = $versionJ;
        $this->versionP = $versionP;
        if ($changed) {
            $this->changedAt = $now;
        }

        return $changed;
    }
}
