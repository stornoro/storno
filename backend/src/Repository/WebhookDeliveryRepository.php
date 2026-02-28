<?php

namespace App\Repository;

use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\Enum\WebhookDeliveryStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WebhookDelivery>
 */
class WebhookDeliveryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebhookDelivery::class);
    }

    public function findByEndpointPaginated(WebhookEndpoint $endpoint, int $page = 1, int $limit = 20): array
    {
        return $this->createQueryBuilder('wd')
            ->where('wd.endpoint = :endpoint')
            ->setParameter('endpoint', $endpoint)
            ->orderBy('wd.triggeredAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByEndpoint(WebhookEndpoint $endpoint): int
    {
        return (int) $this->createQueryBuilder('wd')
            ->select('COUNT(wd.id)')
            ->where('wd.endpoint = :endpoint')
            ->setParameter('endpoint', $endpoint)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findPendingRetries(): array
    {
        return $this->createQueryBuilder('wd')
            ->where('wd.status = :status')
            ->andWhere('wd.nextRetryAt <= :now')
            ->setParameter('status', WebhookDeliveryStatus::RETRYING)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('wd.nextRetryAt', 'ASC')
            ->setMaxResults(100)
            ->getQuery()
            ->getResult();
    }
}
