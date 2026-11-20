<?php

namespace App\EventListener;

use App\Entity\Client;
use App\Entity\Company;
use App\Entity\DeliveryNote;
use App\Entity\EmailLog;
use App\Entity\Invoice;
use App\Entity\Payment;
use App\Entity\ProformaInvoice;
use App\Entity\Receipt;
use App\Entity\RecurringInvoice;
use App\Service\Centrifugo\CentrifugoService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;

/**
 * Tells the open browser tabs of a company that one of its documents changed, whoever changed
 * it: another user, another tab, a worker, a scheduled command. The pages listening on the
 * company's channel refetch what they show.
 *
 * One event per company and kind is queued per flush of the realtime buffer (end of the
 * request, of the handled message or of the command), so a bulk write costs one small call.
 * A failure here must never fail the write itself.
 */
#[AsDoctrineListener(event: Events::onFlush)]
class RealtimeChangeListener
{
    /** entity class → event type */
    private const KINDS = [
        Invoice::class => 'invoice.changed',
        Payment::class => 'invoice.changed',
        EmailLog::class => 'invoice.changed',
        ProformaInvoice::class => 'proforma.changed',
        Receipt::class => 'receipt.changed',
        DeliveryNote::class => 'delivery_note.changed',
        RecurringInvoice::class => 'recurring_invoice.changed',
        Client::class => 'client.changed',
    ];

    public function __construct(
        private readonly CentrifugoService $centrifugo,
        private readonly LoggerInterface $logger,
    ) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        try {
            $uow = $args->getObjectManager()->getUnitOfWork();
            $entities = [
                ...$uow->getScheduledEntityInsertions(),
                ...$uow->getScheduledEntityUpdates(),
                ...$uow->getScheduledEntityDeletions(),
            ];

            $events = [];
            foreach ($entities as $entity) {
                foreach (self::KINDS as $class => $type) {
                    if (!$entity instanceof $class) {
                        continue;
                    }
                    $company = $entity->getCompany();
                    if ($company instanceof Company && $company->getId() !== null) {
                        $events[$company->getId()->toRfc4122() . '|' . $type] = [$company->getId()->toRfc4122(), $type];
                    }
                    break;
                }
            }

            foreach ($events as [$companyId, $type]) {
                $this->centrifugo->queueOnce('invoices:company_' . $companyId, ['type' => $type]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Realtime change event not queued: {error}', ['error' => $e->getMessage()]);
        }
    }
}
