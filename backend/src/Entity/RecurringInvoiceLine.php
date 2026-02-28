<?php

namespace App\Entity;

use App\Entity\Traits\DocumentLineFieldsTrait;
use App\Repository\RecurringInvoiceLineRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: RecurringInvoiceLineRepository::class)]
class RecurringInvoiceLine implements DocumentLineInterface
{
    use DocumentLineFieldsTrait;

    #[ORM\ManyToOne(inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false)]
    private ?RecurringInvoice $recurringInvoice = null;

    #[ORM\Column(length: 3, nullable: true)]
    #[Groups(['recurring_invoice:detail'])]
    private ?string $referenceCurrency = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    #[Groups(['recurring_invoice:detail'])]
    private ?string $markupPercent = null;

    #[ORM\Column(length: 30, options: ['default' => 'fixed'])]
    #[Groups(['recurring_invoice:detail'])]
    private string $priceRule = 'fixed';

    public function __construct()
    {
        $this->initId();
    }

    public function getRecurringInvoice(): ?RecurringInvoice
    {
        return $this->recurringInvoice;
    }

    public function setRecurringInvoice(?RecurringInvoice $recurringInvoice): static
    {
        $this->recurringInvoice = $recurringInvoice;

        return $this;
    }

    public function getReferenceCurrency(): ?string
    {
        return $this->referenceCurrency;
    }

    public function setReferenceCurrency(?string $referenceCurrency): static
    {
        $this->referenceCurrency = $referenceCurrency;

        return $this;
    }

    public function getMarkupPercent(): ?string
    {
        return $this->markupPercent;
    }

    public function setMarkupPercent(?string $markupPercent): static
    {
        $this->markupPercent = $markupPercent;

        return $this;
    }

    public function getPriceRule(): string
    {
        return $this->priceRule;
    }

    public function setPriceRule(string $priceRule): static
    {
        $this->priceRule = $priceRule;

        return $this;
    }

    #[Groups(['recurring_invoice:detail'])]
    public function getProductId(): ?string
    {
        return $this->product ? (string) $this->product->getId() : null;
    }

    #[Groups(['recurring_invoice:detail'])]
    public function getProductName(): ?string
    {
        return $this->product?->getName();
    }
}
