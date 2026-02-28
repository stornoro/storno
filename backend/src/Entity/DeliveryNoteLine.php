<?php

namespace App\Entity;

use App\Entity\Traits\DocumentLineFieldsTrait;
use App\Repository\DeliveryNoteLineRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: DeliveryNoteLineRepository::class)]
class DeliveryNoteLine implements DocumentLineInterface
{
    use DocumentLineFieldsTrait;

    #[ORM\ManyToOne(inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false)]
    private ?DeliveryNote $deliveryNote = null;

    // e-Transport line fields
    #[ORM\Column(length: 8, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?string $tariffCode = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?int $purposeCode = null;

    #[ORM\Column(length: 3, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?string $unitOfMeasureCode = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?string $netWeight = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?string $grossWeight = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2, nullable: true)]
    #[Groups(['delivery_note:detail'])]
    private ?string $valueWithoutVat = null;

    public function __construct()
    {
        $this->initId();
    }

    public function getDeliveryNote(): ?DeliveryNote
    {
        return $this->deliveryNote;
    }

    public function setDeliveryNote(?DeliveryNote $deliveryNote): static
    {
        $this->deliveryNote = $deliveryNote;
        return $this;
    }

    public function getTariffCode(): ?string
    {
        return $this->tariffCode;
    }

    public function setTariffCode(?string $tariffCode): static
    {
        $this->tariffCode = $tariffCode;
        return $this;
    }

    public function getPurposeCode(): ?int
    {
        return $this->purposeCode;
    }

    public function setPurposeCode(?int $purposeCode): static
    {
        $this->purposeCode = $purposeCode;
        return $this;
    }

    public function getUnitOfMeasureCode(): ?string
    {
        return $this->unitOfMeasureCode;
    }

    public function setUnitOfMeasureCode(?string $unitOfMeasureCode): static
    {
        $this->unitOfMeasureCode = $unitOfMeasureCode;
        return $this;
    }

    public function getNetWeight(): ?string
    {
        return $this->netWeight;
    }

    public function setNetWeight(?string $netWeight): static
    {
        $this->netWeight = $netWeight;
        return $this;
    }

    public function getGrossWeight(): ?string
    {
        return $this->grossWeight;
    }

    public function setGrossWeight(?string $grossWeight): static
    {
        $this->grossWeight = $grossWeight;
        return $this;
    }

    public function getValueWithoutVat(): ?string
    {
        return $this->valueWithoutVat;
    }

    public function setValueWithoutVat(?string $valueWithoutVat): static
    {
        $this->valueWithoutVat = $valueWithoutVat;
        return $this;
    }
}
