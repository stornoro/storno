<?php

namespace App\Event\Invoice;

use App\Entity\Invoice;
use Symfony\Contracts\EventDispatcher\Event;

class InvoiceSentToAnafEvent extends Event
{
    public const NAME = 'invoice.sent_to_anaf';

    public function __construct(
        private readonly Invoice $invoice,
    ) {
    }

    public function getInvoice(): Invoice
    {
        return $this->invoice;
    }
}
