<?php

namespace App\Entity\Traits;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Snapshot of the last registry check of a partner (client or supplier):
 * ANAF for Romanian companies (VAT registration, VAT on collection, inactive
 * status, RO e-Factura register), VIES for EU partners. `null` means "not
 * known" — never checked, or the registry did not answer.
 */
trait PartnerVerificationTrait
{
    #[ORM\Column(nullable: true)]
    #[Groups(['client:list', 'client:detail', 'supplier:list', 'supplier:detail'])]
    private ?\DateTimeImmutable $vatStatusCheckedAt = null;

    #[ORM\Column(type: 'boolean', nullable: true)]
    #[Groups(['client:list', 'client:detail', 'supplier:list', 'supplier:detail'])]
    private ?bool $vatRegistered = null;

    #[ORM\Column(type: 'boolean', nullable: true)]
    #[Groups(['client:list', 'client:detail', 'supplier:list', 'supplier:detail'])]
    private ?bool $vatOnCollection = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['client:detail', 'supplier:detail'])]
    private ?\DateTimeImmutable $vatOnCollectionFrom = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['client:detail', 'supplier:detail'])]
    private ?\DateTimeImmutable $vatOnCollectionTo = null;

    #[ORM\Column(type: 'boolean', nullable: true)]
    #[Groups(['client:list', 'client:detail', 'supplier:list', 'supplier:detail'])]
    private ?bool $inactive = null;

    #[ORM\Column(type: 'boolean', nullable: true)]
    #[Groups(['client:list', 'client:detail', 'supplier:list', 'supplier:detail'])]
    private ?bool $efacturaRegistered = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['client:detail', 'supplier:detail'])]
    private ?string $verificationNotes = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['client:list', 'client:detail', 'supplier:list', 'supplier:detail'])]
    private bool $affiliated = false;

    public function getVatStatusCheckedAt(): ?\DateTimeImmutable
    {
        return $this->vatStatusCheckedAt;
    }

    public function setVatStatusCheckedAt(?\DateTimeImmutable $at): static
    {
        $this->vatStatusCheckedAt = $at;

        return $this;
    }

    public function isVatRegistered(): ?bool
    {
        return $this->vatRegistered;
    }

    public function setVatRegistered(?bool $vatRegistered): static
    {
        $this->vatRegistered = $vatRegistered;

        return $this;
    }

    public function isVatOnCollection(): ?bool
    {
        return $this->vatOnCollection;
    }

    public function setVatOnCollection(?bool $vatOnCollection): static
    {
        $this->vatOnCollection = $vatOnCollection;

        return $this;
    }

    public function getVatOnCollectionFrom(): ?\DateTimeImmutable
    {
        return $this->vatOnCollectionFrom;
    }

    public function setVatOnCollectionFrom(?\DateTimeImmutable $from): static
    {
        $this->vatOnCollectionFrom = $from;

        return $this;
    }

    public function getVatOnCollectionTo(): ?\DateTimeImmutable
    {
        return $this->vatOnCollectionTo;
    }

    public function setVatOnCollectionTo(?\DateTimeImmutable $to): static
    {
        $this->vatOnCollectionTo = $to;

        return $this;
    }

    public function isInactive(): ?bool
    {
        return $this->inactive;
    }

    public function setInactive(?bool $inactive): static
    {
        $this->inactive = $inactive;

        return $this;
    }

    public function isEfacturaRegistered(): ?bool
    {
        return $this->efacturaRegistered;
    }

    public function setEfacturaRegistered(?bool $efacturaRegistered): static
    {
        $this->efacturaRegistered = $efacturaRegistered;

        return $this;
    }

    public function getVerificationNotes(): ?string
    {
        return $this->verificationNotes;
    }

    public function setVerificationNotes(?string $notes): static
    {
        $this->verificationNotes = $notes === null ? null : mb_substr($notes, 0, 500);

        return $this;
    }

    public function isAffiliated(): bool
    {
        return $this->affiliated;
    }

    public function setAffiliated(bool $affiliated): static
    {
        $this->affiliated = $affiliated;

        return $this;
    }
}
