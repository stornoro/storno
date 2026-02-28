<?php

namespace App\Event\Invoice;

use App\Entity\Invoice;
use Symfony\Contracts\EventDispatcher\Event;

class InvoiceSentToProviderEvent extends Event
{
    public const NAME = 'invoice.sent_to_provider';

    public function __construct(
        private readonly Invoice $invoice,
    ) {
    }

    public function getInvoice(): Invoice
    {
        return $this->invoice;
    }
}
