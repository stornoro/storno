<?php

namespace App\Entity;

use App\Entity\Traits\DocumentLineFieldsTrait;
use App\Repository\ReceiptLineRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ReceiptLineRepository::class)]
class ReceiptLine implements DocumentLineInterface
{
    use DocumentLineFieldsTrait;

    #[ORM\ManyToOne(inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Receipt $receipt = null;

    public function __construct()
    {
        $this->initId();
    }

    public function getReceipt(): ?Receipt
    {
        return $this->receipt;
    }

    public function setReceipt(?Receipt $receipt): static
    {
        $this->receipt = $receipt;

        return $this;
    }
}
