<?php

namespace App\EventSubscriber;

use App\Event\Invoice\InvoiceCreatedEvent;
use App\Event\Invoice\InvoiceIssuedEvent;
use App\Event\Invoice\InvoiceRejectedEvent;
use App\Event\Invoice\InvoiceSentToProviderEvent;
use App\Event\Invoice\InvoiceValidatedEvent;
use App\Service\Webhook\WebhookDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class WebhookEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly WebhookDispatcher $webhookDispatcher,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            InvoiceCreatedEvent::NAME => 'onInvoiceCreated',
            InvoiceIssuedEvent::NAME => 'onInvoiceIssued',
            InvoiceValidatedEvent::NAME => 'onInvoiceValidated',
            InvoiceRejectedEvent::NAME => 'onInvoiceRejected',
            InvoiceSentToProviderEvent::NAME => 'onInvoiceSentToProvider',
        ];
    }

    public function onInvoiceCreated(InvoiceCreatedEvent $event): void
    {
        $this->dispatchInvoiceWebhook('invoice.created', $event->getInvoice());
    }

    public function onInvoiceIssued(InvoiceIssuedEvent $event): void
    {
        $this->dispatchInvoiceWebhook('invoice.issued', $event->getInvoice());
    }

    public function onInvoiceValidated(InvoiceValidatedEvent $event): void
    {
        $this->dispatchInvoiceWebhook('invoice.validated', $event->getInvoice());
    }

    public function onInvoiceRejected(InvoiceRejectedEvent $event): void
    {
        $this->dispatchInvoiceWebhook('invoice.rejected', $event->getInvoice());
    }

    public function onInvoiceSentToProvider(InvoiceSentToProviderEvent $event): void
    {
        $this->dispatchInvoiceWebhook('invoice.sent_to_provider', $event->getInvoice());
    }

    private function dispatchInvoiceWebhook(string $eventType, \App\Entity\Invoice $invoice): void
    {
        $company = $invoice->getCompany();
        if (!$company) {
            return;
        }

        $this->webhookDispatcher->dispatchForCompany($company, $eventType, [
            'id' => $invoice->getId()?->toRfc4122(),
            'number' => $invoice->getNumber(),
            'status' => $invoice->getStatus()->value,
            'direction' => $invoice->getDirection()?->value,
            'total' => $invoice->getTotal(),
            'currency' => $invoice->getCurrency(),
        ]);
    }
}
