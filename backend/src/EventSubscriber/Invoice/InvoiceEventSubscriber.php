<?php

namespace App\EventSubscriber\Invoice;

use App\Entity\Invoice;
use App\Event\Invoice\InvoiceCreatedEvent;
use App\Event\Invoice\InvoiceIssuedEvent;
use App\Event\Invoice\InvoiceRejectedEvent;
use App\Event\Invoice\InvoiceSentToProviderEvent;
use App\Event\Invoice\InvoiceValidatedEvent;
use App\Message\GeneratePdfMessage;
use App\Service\Centrifugo\CentrifugoService;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class InvoiceEventSubscriber implements EventSubscriberInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CentrifugoService $centrifugo,
        LoggerInterface $logger,
    ) {
        $this->setLogger($logger);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            InvoiceIssuedEvent::NAME => 'onInvoiceIssued',
            InvoiceSentToProviderEvent::NAME => 'onInvoiceSentToProvider',
            InvoiceCreatedEvent::NAME => 'onInvoiceCreated',
            InvoiceValidatedEvent::NAME => 'onInvoiceValidated',
            InvoiceRejectedEvent::NAME => 'onInvoiceRejected',
        ];
    }

    public function onInvoiceIssued(InvoiceIssuedEvent $event): void
    {
        $invoice = $event->getInvoice();

        $this->logger->info('Invoice issued, dispatching PDF generation.', [
            'invoiceId' => $invoice->getId()?->toRfc4122(),
            'number' => $invoice->getNumber(),
            'documentType' => $invoice->getDocumentType()->value,
        ]);

        $this->messageBus->dispatch(
            new GeneratePdfMessage(
                invoiceId: $invoice->getId()->toRfc4122(),
            )
        );
    }

    public function onInvoiceSentToProvider(InvoiceSentToProviderEvent $event): void
    {
        $invoice = $event->getInvoice();

        $this->logger->info('Invoice sent to e-invoice provider.', [
            'invoiceId' => $invoice->getId()?->toRfc4122(),
            'number' => $invoice->getNumber(),
            'uploadId' => $invoice->getAnafUploadId(),
        ]);
    }

    public function onInvoiceCreated(InvoiceCreatedEvent $event): void
    {
        $this->queueInvoiceEvent('invoice.created', $event->getInvoice());
    }

    public function onInvoiceValidated(InvoiceValidatedEvent $event): void
    {
        $this->queueInvoiceEvent('invoice.validated', $event->getInvoice());
    }

    public function onInvoiceRejected(InvoiceRejectedEvent $event): void
    {
        $this->queueInvoiceEvent('invoice.rejected', $event->getInvoice());
    }

    private function queueInvoiceEvent(string $type, Invoice $invoice): void
    {
        $company = $invoice->getCompany();
        if (!$company) {
            return;
        }

        $channel = 'invoices:company_' . $company->getId()->toRfc4122();

        $this->centrifugo->queue($channel, [
            'type' => $type,
            'invoice' => [
                'id' => $invoice->getId()?->toRfc4122(),
                'number' => $invoice->getNumber(),
                'status' => $invoice->getStatus()->value,
                'direction' => $invoice->getDirection()?->value,
                'total' => $invoice->getTotal(),
                'currency' => $invoice->getCurrency(),
            ],
        ]);
    }
}
