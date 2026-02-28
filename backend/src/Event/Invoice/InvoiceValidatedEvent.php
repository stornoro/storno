<?php

namespace App\Event\Invoice;

use App\Entity\Invoice;
use Symfony\Contracts\EventDispatcher\Event;

class InvoiceValidatedEvent extends Event
{
    public const NAME = 'invoice.validated';

    public function __construct(
        private readonly Invoice $invoice,
    ) {
    }

    public function getInvoice(): Invoice
    {
        return $this->invoice;
    }
}
