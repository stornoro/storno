<?php

namespace App\Repository;

use App\Entity\ProcessedWebhookEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProcessedWebhookEvent>
 */
class ProcessedWebhookEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProcessedWebhookEvent::class);
    }

    /**
     * Check whether a Stripe event ID has already been processed.
     */
    public function isProcessed(string $eventId): bool
    {
        return $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.eventId = :eventId')
            ->setParameter('eventId', $eventId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Persist a record marking the event as processed.
     */
    public function markProcessed(string $eventId, string $eventType): void
    {
        $event = new ProcessedWebhookEvent($eventId, $eventType);
        $em = $this->getEntityManager();
        $em->persist($event);
        $em->flush();
    }
}
