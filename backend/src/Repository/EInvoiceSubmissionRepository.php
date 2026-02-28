<?php

namespace App\Repository;

use App\Entity\EInvoiceSubmission;
use App\Entity\Invoice;
use App\Enum\EInvoiceProvider;
use App\Enum\EInvoiceSubmissionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class EInvoiceSubmissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EInvoiceSubmission::class);
    }

    /**
     * @return EInvoiceSubmission[]
     */
    public function findByInvoice(Invoice $invoice): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.invoice = :invoice')
            ->setParameter('invoice', $invoice)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByInvoiceAndProvider(Invoice $invoice, EInvoiceProvider $provider): ?EInvoiceSubmission
    {
        return $this->createQueryBuilder('s')
            ->where('s.invoice = :invoice')
            ->andWhere('s.provider = :provider')
            ->setParameter('invoice', $invoice)
            ->setParameter('provider', $provider)
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return EInvoiceSubmission[]
     */
    public function findPendingByProvider(EInvoiceProvider $provider, int $limit = 50): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.provider = :provider')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('provider', $provider)
            ->setParameter('statuses', [EInvoiceSubmissionStatus::PENDING, EInvoiceSubmissionStatus::SUBMITTED])
            ->orderBy('s.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Compute global ANAF submission stats for the last 72 hours.
     */
    public function getAnafStats72h(): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $since = (new \DateTimeImmutable('-72 hours'))->format('Y-m-d H:i:s');

        // Count totals, successes, and ANAF upload failures (errors not related to token)
        $sql = "
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status IN ('accepted', 'submitted') THEN 1 ELSE 0 END) AS successful,
                SUM(CASE WHEN status = 'error' AND (error_message IS NULL OR error_message NOT LIKE '%token%') THEN 1 ELSE 0 END) AS upload_failed
            FROM einvoice_submission
            WHERE provider = 'anaf'
              AND updated_at >= ?
        ";
        $row = $conn->fetchAssociative($sql, [$since]);

        $total = (int) ($row['total'] ?? 0);
        $successful = (int) ($row['successful'] ?? 0);
        $uploadFailed = (int) ($row['upload_failed'] ?? 0);
        $successRate = $total > 0 ? round(($successful / $total) * 100, 1) : null;

        // Estimate downtime: count distinct 10-minute windows with upload errors
        $downtimeSql = "
            SELECT COUNT(DISTINCT FLOOR(UNIX_TIMESTAMP(updated_at) / 600)) AS error_windows
            FROM einvoice_submission
            WHERE provider = 'anaf'
              AND status = 'error'
              AND (error_message IS NULL OR error_message NOT LIKE '%token%')
              AND updated_at >= ?
        ";
        $downtimeRow = $conn->fetchAssociative($downtimeSql, [$since]);
        $errorWindows = (int) ($downtimeRow['error_windows'] ?? 0);
        $downtimeMinutes = $errorWindows * 10;
        $downtimeHours = round($downtimeMinutes / 60, 1);
        $uptimeHours = round(72 - $downtimeHours, 1);

        // Last successful submission
        $lastSuccessSql = "
            SELECT updated_at FROM einvoice_submission
            WHERE provider = 'anaf' AND status IN ('accepted', 'submitted')
            ORDER BY updated_at DESC LIMIT 1
        ";
        $lastSuccess = $conn->fetchOne($lastSuccessSql);

        // Last failed submission (ANAF upload error, not token)
        $lastFailureSql = "
            SELECT updated_at, error_message FROM einvoice_submission
            WHERE provider = 'anaf'
              AND status = 'error'
              AND (error_message IS NULL OR error_message NOT LIKE '%token%')
            ORDER BY updated_at DESC LIMIT 1
        ";
        $lastFailure = $conn->fetchAssociative($lastFailureSql);

        // Next scheduled retry
        $nextRetrySql = "
            SELECT i.scheduled_send_at FROM invoice i
            INNER JOIN einvoice_submission s ON s.invoice_id = i.id
            WHERE s.provider = 'anaf'
              AND s.status = 'error'
              AND i.scheduled_send_at > NOW()
            ORDER BY i.scheduled_send_at ASC LIMIT 1
        ";
        $nextRetry = $conn->fetchOne($nextRetrySql);

        return [
            'downtimeHours' => $downtimeHours,
            'uptimeHours' => $uptimeHours,
            'successRate' => $successRate,
            'totalSubmissions' => $total,
            'successfulSubmissions' => $successful,
            'lastSuccessAt' => $lastSuccess ?: null,
            'lastFailureAt' => $lastFailure ? $lastFailure['updated_at'] : null,
            'lastFailureMessage' => $lastFailure ? $lastFailure['error_message'] : null,
            'nextRetryAt' => $nextRetry ?: null,
        ];
    }
}
