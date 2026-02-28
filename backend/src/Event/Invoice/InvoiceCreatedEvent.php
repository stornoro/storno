<?php

namespace App\Event\Invoice;

use App\Entity\Invoice;
use Symfony\Contracts\EventDispatcher\Event;

class InvoiceCreatedEvent extends Event
{
    public const NAME = 'invoice.created';

    public function __construct(
        private readonly Invoice $invoice,
    ) {
    }

    public function getInvoice(): Invoice
    {
        return $this->invoice;
    }
}
