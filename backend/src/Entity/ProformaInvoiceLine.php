<?php

namespace App\Entity;

use App\Entity\Traits\DocumentLineFieldsTrait;
use App\Repository\ProformaInvoiceLineRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProformaInvoiceLineRepository::class)]
class ProformaInvoiceLine implements DocumentLineInterface
{
    use DocumentLineFieldsTrait;

    #[ORM\ManyToOne(inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false)]
    private ?ProformaInvoice $proformaInvoice = null;

    public function __construct()
    {
        $this->initId();
    }

    public function getProformaInvoice(): ?ProformaInvoice
    {
        return $this->proformaInvoice;
    }

    public function setProformaInvoice(?ProformaInvoice $proformaInvoice): static
    {
        $this->proformaInvoice = $proformaInvoice;

        return $this;
    }
}
