<?php

namespace App\Entity;

use App\Entity\Traits\DocumentLineFieldsTrait;
use App\Repository\InvoiceLineRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvoiceLineRepository::class)]
class InvoiceLine implements DocumentLineInterface
{
    use DocumentLineFieldsTrait;

    #[ORM\ManyToOne(inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Invoice $invoice = null;

    public function __construct()
    {
        $this->initId();
    }

    public function getInvoice(): ?Invoice
    {
        return $this->invoice;
    }

    public function setInvoice(?Invoice $invoice): static
    {
        $this->invoice = $invoice;

        return $this;
    }
}
