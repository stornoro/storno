<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\Invoice;
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

    /**
     * Find webhook deliveries whose payload references the given invoice.
     * Deliveries don't have a direct foreign key to invoices; the link lives
     * inside the JSON payload under `$.data.id` (set by WebhookEventSubscriber).
     * We additionally scope to endpoints of the invoice's company so one
     * tenant can't fish deliveries out of another's table.
     *
     * Uses raw SQL because JSON_EXTRACT / JSON_UNQUOTE aren't registered as
     * DQL functions in this project (and the non-JSON LIKE fallback is
     * fragile given MySQL may reorder JSON keys).
     *
     * @return WebhookDelivery[]
     */
    public function findByInvoicePaginated(Invoice $invoice, int $page = 1, int $limit = 20): array
    {
        $company = $invoice->getCompany();
        if (!$company) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();
        $rows = $conn->fetchAllAssociative(
            "SELECT wd.id AS id
             FROM webhook_delivery wd
             INNER JOIN webhook_endpoint we ON we.id = wd.endpoint_id
             WHERE we.company_id = :companyId
               AND JSON_UNQUOTE(JSON_EXTRACT(wd.payload, '$.data.id')) = :invoiceId
             ORDER BY wd.triggered_at DESC
             LIMIT :limit OFFSET :offset",
            [
                'companyId' => $company->getId()->toRfc4122(),
                'invoiceId' => $invoice->getId()->toRfc4122(),
                'limit' => $limit,
                'offset' => ($page - 1) * $limit,
            ],
            [
                'limit' => \PDO::PARAM_INT,
                'offset' => \PDO::PARAM_INT,
            ],
        );

        if (empty($rows)) {
            return [];
        }

        $ids = array_map(static fn ($r) => $r['id'], $rows);

        // Second pass: load the actual entities, preserving the order from the
        // raw query (MySQL returns binary/UUID ids; we need the Uuid instances
        // back as hydrated entities for downstream serialization).
        $deliveries = $this->createQueryBuilder('wd')
            ->where('wd.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($deliveries as $d) {
            $byId[$d->getId()->toRfc4122()] = $d;
        }

        $ordered = [];
        foreach ($ids as $id) {
            // $id may come back from the DB as a binary string — normalise.
            $key = $id instanceof \Symfony\Component\Uid\Uuid
                ? $id->toRfc4122()
                : (is_string($id) && strlen($id) === 16 ? \Symfony\Component\Uid\Uuid::fromBinary($id)->toRfc4122() : (string) $id);
            if (isset($byId[$key])) {
                $ordered[] = $byId[$key];
            }
        }

        return $ordered;
    }

    public function countByInvoice(Invoice $invoice): int
    {
        $company = $invoice->getCompany();
        if (!$company) {
            return 0;
        }

        $conn = $this->getEntityManager()->getConnection();

        return (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM webhook_delivery wd
             INNER JOIN webhook_endpoint we ON we.id = wd.endpoint_id
             WHERE we.company_id = :companyId
               AND JSON_UNQUOTE(JSON_EXTRACT(wd.payload, '$.data.id')) = :invoiceId",
            [
                'companyId' => $company->getId()->toRfc4122(),
                'invoiceId' => $invoice->getId()->toRfc4122(),
            ],
        );
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
