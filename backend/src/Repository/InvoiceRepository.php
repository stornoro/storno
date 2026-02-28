<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\EInvoiceSubmission;
use App\Entity\Invoice;
use App\Enum\DocumentStatus;
use App\Enum\DocumentType;
use App\Enum\EInvoiceSubmissionStatus;
use App\Enum\InvoiceDirection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Invoice>
 */
class InvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invoice::class);
    }

    /**
     * Batch check which ANAF message IDs already exist. Returns associative array [messageId => true].
     */
    public function findExistingAnafMessageIds(array $messageIds): array
    {
        if (empty($messageIds)) {
            return [];
        }

        $rows = $this->createQueryBuilder('i')
            ->select('i.anafMessageId')
            ->where('i.anafMessageId IN (:ids)')
            ->setParameter('ids', $messageIds)
            ->getQuery()
            ->getSingleColumnResult();

        return array_flip($rows);
    }

    public function findWithDetails(string $uuid): ?Invoice
    {
        return $this->createQueryBuilder('i')
            ->leftJoin('i.client', 'c')->addSelect('c')
            ->leftJoin('i.company', 'co')->addSelect('co')
            ->leftJoin('i.lines', 'il')->addSelect('il')
            ->where('i.id = :id')
            ->setParameter('id', $uuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return array<array{id: string, number: string|null, issueDate: string|null, status: string}>
     */
    public function findRefundChildren(Invoice $invoice): array
    {
        $results = $this->createQueryBuilder('i')
            ->select('i.id', 'i.number', 'i.issueDate', 'i.status')
            ->where('i.parentDocument = :parent')
            ->setParameter('parent', $invoice)
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return array_map(fn (array $row) => [
            'id' => $row['id'] instanceof \Symfony\Component\Uid\Uuid ? $row['id']->toRfc4122() : (string) $row['id'],
            'number' => $row['number'],
            'issueDate' => $row['issueDate'] instanceof \DateTimeInterface ? $row['issueDate']->format('Y-m-d') : $row['issueDate'],
            'status' => $row['status'] instanceof DocumentStatus ? $row['status']->value : (string) $row['status'],
        ], $results);
    }

    private const SORTABLE_COLUMNS = ['number', 'issueDate', 'total', 'status', 'direction', 'createdAt'];

    public function findByCompanyPaginated(Company $company, array $filters = [], int $page = 1, int $limit = 20): array
    {
        $sortField = 'issueDate';
        $sortOrder = 'DESC';

        if (isset($filters['sort']) && in_array($filters['sort'], self::SORTABLE_COLUMNS, true)) {
            $sortField = $filters['sort'];
        }
        if (isset($filters['order']) && in_array(strtoupper($filters['order']), ['ASC', 'DESC'], true)) {
            $sortOrder = strtoupper($filters['order']);
        }

        $qb = $this->createQueryBuilder('i')
            ->leftJoin('i.client', 'c')->addSelect('c')
            ->where('i.company = :company')
            ->setParameter('company', $company)
            ->orderBy('i.' . $sortField, $sortOrder);

        $this->applyFilters($qb, $filters);

        $qb->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        $paginator = new Paginator($qb);

        // Aggregated totals across all filtered results (not just current page)
        $totalsQb = $this->createQueryBuilder('i')
            ->select(
                'SUM(i.subtotal) as totalExcluding',
                'SUM(i.vatTotal) as totalVat',
                'SUM(i.total) as totalIncluding',
                'SUM(CASE WHEN i.direction = :outgoing AND i.paidAt IS NULL THEN (i.total - i.amountPaid) ELSE 0 END) as totalReceivable',
                'SUM(CASE WHEN i.direction = :incoming AND i.paidAt IS NULL THEN (i.total - i.amountPaid) ELSE 0 END) as totalPayable',
            )
            ->leftJoin('i.client', 'c')
            ->where('i.company = :company')
            ->setParameter('company', $company)
            ->setParameter('outgoing', InvoiceDirection::OUTGOING->value)
            ->setParameter('incoming', InvoiceDirection::INCOMING->value);

        $this->applyFilters($totalsQb, $filters);

        $totals = $totalsQb->getQuery()->getSingleResult();

        return [
            'data' => iterator_to_array($paginator),
            'total' => count($paginator),
            'page' => $page,
            'limit' => $limit,
            'totals' => [
                'subtotal' => $totals['totalExcluding'] ?? '0.00',
                'vatTotal' => $totals['totalVat'] ?? '0.00',
                'total' => $totals['totalIncluding'] ?? '0.00',
                'receivable' => $totals['totalReceivable'] ?? '0.00',
                'payable' => $totals['totalPayable'] ?? '0.00',
            ],
        ];
    }

    private function applyFilters(\Doctrine\ORM\QueryBuilder $qb, array $filters): void
    {
        if (isset($filters['type'])) {
            $qb->andWhere('i.documentType = :type')
                ->setParameter('type', DocumentType::from($filters['type']));
        }

        if (isset($filters['status'])) {
            $qb->andWhere('i.status = :status')
                ->setParameter('status', DocumentStatus::from($filters['status']));
        }

        if (isset($filters['direction'])) {
            $qb->andWhere('i.direction = :direction')
                ->setParameter('direction', InvoiceDirection::from($filters['direction']));
        }

        if (isset($filters['clientId'])) {
            $qb->andWhere('i.client = :clientId')
                ->setParameter('clientId', $filters['clientId']);
        }

        if (isset($filters['search'])) {
            $qb->andWhere('i.number LIKE :search OR c.name LIKE :search OR i.senderName LIKE :search OR i.receiverName LIKE :search')
                ->setParameter('search', '%' . $filters['search'] . '%');
        }

        if (isset($filters['dateFrom'])) {
            $qb->andWhere('i.issueDate >= :dateFrom')
                ->setParameter('dateFrom', new \DateTime($filters['dateFrom']));
        }

        if (isset($filters['dateTo'])) {
            $qb->andWhere('i.issueDate <= :dateTo')
                ->setParameter('dateTo', new \DateTime($filters['dateTo']));
        }

        if (isset($filters['isDuplicate'])) {
            $qb->andWhere('i.isDuplicate = :isDuplicate')
                ->setParameter('isDuplicate', filter_var($filters['isDuplicate'], FILTER_VALIDATE_BOOLEAN));
        }

        if (isset($filters['isLateSubmission'])) {
            $qb->andWhere('i.isLateSubmission = :isLateSubmission')
                ->setParameter('isLateSubmission', filter_var($filters['isLateSubmission'], FILTER_VALIDATE_BOOLEAN));
        }

        if (isset($filters['isPaid'])) {
            if (filter_var($filters['isPaid'], FILTER_VALIDATE_BOOLEAN)) {
                $qb->andWhere('i.paidAt IS NOT NULL');
            } else {
                $qb->andWhere('i.paidAt IS NULL');
            }
        }

        if (isset($filters['isOverdue']) && filter_var($filters['isOverdue'], FILTER_VALIDATE_BOOLEAN)) {
            $qb->andWhere('i.dueDate < :now')
                ->andWhere('i.paidAt IS NULL')
                ->setParameter('now', new \DateTime());
        }

        if (isset($filters['supplierId'])) {
            $qb->andWhere('i.supplier = :supplierId')
                ->setParameter('supplierId', $filters['supplierId']);
        }
    }

    public function findByCompanyFiltered(Company $company, array $filters = [], int $limit = 500): array
    {
        $qb = $this->createQueryBuilder('i')
            ->leftJoin('i.lines', 'il')->addSelect('il')
            ->leftJoin('i.client', 'c')->addSelect('c')
            ->where('i.company = :company')
            ->setParameter('company', $company)
            ->orderBy('i.issueDate', 'DESC')
            ->setMaxResults($limit);

        if (isset($filters['type'])) {
            $qb->andWhere('i.documentType = :type')
                ->setParameter('type', DocumentType::from($filters['type']));
        }

        if (isset($filters['status'])) {
            $qb->andWhere('i.status = :status')
                ->setParameter('status', DocumentStatus::from($filters['status']));
        }

        if (isset($filters['direction'])) {
            $qb->andWhere('i.direction = :direction')
                ->setParameter('direction', InvoiceDirection::from($filters['direction']));
        }

        if (isset($filters['search'])) {
            $qb->andWhere('i.number LIKE :search OR c.name LIKE :search OR i.senderName LIKE :search OR i.receiverName LIKE :search')
                ->setParameter('search', '%' . $filters['search'] . '%');
        }

        if (isset($filters['dateFrom'])) {
            $qb->andWhere('i.issueDate >= :dateFrom')
                ->setParameter('dateFrom', new \DateTime($filters['dateFrom']));
        }

        if (isset($filters['dateTo'])) {
            $qb->andWhere('i.issueDate <= :dateTo')
                ->setParameter('dateTo', new \DateTime($filters['dateTo']));
        }

        if (isset($filters['isPaid'])) {
            if (filter_var($filters['isPaid'], FILTER_VALIDATE_BOOLEAN)) {
                $qb->andWhere('i.paidAt IS NOT NULL');
            } else {
                $qb->andWhere('i.paidAt IS NULL');
            }
        }

        if (isset($filters['isOverdue']) && filter_var($filters['isOverdue'], FILTER_VALIDATE_BOOLEAN)) {
            $qb->andWhere('i.dueDate < :now')
                ->andWhere('i.paidAt IS NULL')
                ->setParameter('now', new \DateTime());
        }

        if (isset($filters['isDuplicate'])) {
            $qb->andWhere('i.isDuplicate = :isDuplicate')
                ->setParameter('isDuplicate', filter_var($filters['isDuplicate'], FILTER_VALIDATE_BOOLEAN));
        }

        if (isset($filters['clientId'])) {
            $qb->andWhere('i.client = :clientId')
                ->setParameter('clientId', $filters['clientId']);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find invoices by CIF, optionally filtered by direction.
     * OUTGOING → match receiverCif (client = buyer), INCOMING → match senderCif (supplier = seller).
     */
    public function findByCifPaginated(Company $company, string $cif, int $page = 1, int $limit = 50, ?InvoiceDirection $direction = null): array
    {
        $qb = $this->createQueryBuilder('i')
            ->leftJoin('i.client', 'c')->addSelect('c')
            ->where('i.company = :company')
            ->setParameter('company', $company)
            ->orderBy('i.issueDate', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        if ($direction === InvoiceDirection::OUTGOING) {
            $qb->andWhere('i.receiverCif = :cif')
                ->andWhere('i.direction = :direction')
                ->setParameter('cif', $cif)
                ->setParameter('direction', $direction);
        } elseif ($direction === InvoiceDirection::INCOMING) {
            $qb->andWhere('i.senderCif = :cif')
                ->andWhere('i.direction = :direction')
                ->setParameter('cif', $cif)
                ->setParameter('direction', $direction);
        } else {
            $qb->andWhere('i.senderCif = :cif OR i.receiverCif = :cif')
                ->setParameter('cif', $cif);
        }

        $paginator = new Paginator($qb);

        return [
            'data' => iterator_to_array($paginator),
            'total' => count($paginator),
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * Count invoices where senderCif or receiverCif matches the given CIF.
     */
    public function countByCif(Company $company, string $cif): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.company = :company')
            ->andWhere('i.senderCif = :cif OR i.receiverCif = :cif')
            ->setParameter('company', $company)
            ->setParameter('cif', $cif)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get invoice counts and totals grouped by CIF for a list of CIFs.
     * Uses native SQL with UNION to leverage the new CIF indexes.
     * Returns [cif => ['invoiceCount' => int, 'invoiceTotal' => float]]
     */
    public function getStatsGroupedByCif(Company $company, array $cifs): array
    {
        if (empty($cifs)) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();

        $placeholders = implode(',', array_fill(0, count($cifs), '?'));
        $companyId = $company->getId()->toRfc4122();

        $sql = "
            SELECT cif, COUNT(*) AS invoiceCount, COALESCE(SUM(total), 0) AS invoiceTotal
            FROM (
                SELECT sender_cif AS cif, total FROM invoice
                WHERE company_id = ? AND sender_cif IN ($placeholders)
                UNION ALL
                SELECT receiver_cif AS cif, total FROM invoice
                WHERE company_id = ? AND receiver_cif IN ($placeholders)
            ) AS combined
            GROUP BY cif
        ";

        $params = array_merge([$companyId], $cifs, [$companyId], $cifs);
        $rows = $conn->fetchAllAssociative($sql, $params);

        $stats = [];
        foreach ($rows as $row) {
            $stats[$row['cif']] = [
                'invoiceCount' => (int) $row['invoiceCount'],
                'invoiceTotal' => round((float) $row['invoiceTotal'], 2),
            ];
        }

        return $stats;
    }

    /**
     * @param string[] $ids
     * @return Invoice[]
     */
    public function findByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return $this->createQueryBuilder('i')
            ->where('i.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Invoice[]
     */
    public function findScheduledForSubmission(\DateTimeImmutable $now, int $limit = 100): array
    {
        // Exclude invoices that already have a pending or submitted e-invoice submission
        $subQuery = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(EInvoiceSubmission::class, 's')
            ->where('s.invoice = i')
            ->andWhere('s.status IN (:activeStatuses)')
            ->getDQL();

        return $this->createQueryBuilder('i')
            ->join('i.company', 'c')
            ->where('i.status = :status')
            ->andWhere('i.scheduledSendAt <= :now')
            ->andWhere('i.direction = :direction')
            ->andWhere('i.deletedAt IS NULL')
            ->andWhere('c.deletedAt IS NULL')
            ->andWhere(sprintf('NOT EXISTS (%s)', $subQuery))
            ->setParameter('status', DocumentStatus::ISSUED)
            ->setParameter('now', $now)
            ->setParameter('direction', InvoiceDirection::OUTGOING)
            ->setParameter('activeStatuses', [EInvoiceSubmissionStatus::PENDING, EInvoiceSubmissionStatus::SUBMITTED])
            ->orderBy('i.scheduledSendAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Invoice[]
     */
    public function findExpiredArchived(Company $company, \DateTimeImmutable $cutoffDate): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.company = :company')
            ->andWhere('i.xmlPath IS NOT NULL')
            ->andWhere('i.issueDate < :cutoff')
            ->setParameter('company', $company)
            ->setParameter('cutoff', $cutoffDate)
            ->getQuery()
            ->getResult();
    }

    public function countActiveArchived(Company $company, \DateTimeImmutable $cutoffDate): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.company = :company')
            ->andWhere('i.xmlPath IS NOT NULL')
            ->andWhere('i.issueDate >= :cutoff')
            ->setParameter('company', $company)
            ->setParameter('cutoff', $cutoffDate)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOverdueInvoices(Company $company): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.company = :company')
            ->andWhere('i.dueDate < :now')
            ->andWhere('i.paidAt IS NULL')
            ->andWhere('i.status IN (:statuses)')
            ->setParameter('company', $company)
            ->setParameter('now', new \DateTime())
            ->setParameter('statuses', [DocumentStatus::ISSUED, DocumentStatus::VALIDATED, DocumentStatus::SYNCED, DocumentStatus::SENT_TO_PROVIDER])
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Invoice[]
     */
    public function findDueInDays(int $days): array
    {
        $targetDate = new \DateTime(sprintf('+%d days', $days));

        return $this->createQueryBuilder('i')
            ->leftJoin('i.company', 'c')->addSelect('c')
            ->leftJoin('i.client', 'cl')->addSelect('cl')
            ->where('i.dueDate >= :start')
            ->andWhere('i.dueDate < :end')
            ->andWhere('i.paidAt IS NULL')
            ->andWhere('i.direction = :direction')
            ->andWhere('i.status IN (:statuses)')
            ->andWhere('c.syncEnabled = true')
            ->setParameter('start', $targetDate->format('Y-m-d') . ' 00:00:00')
            ->setParameter('end', $targetDate->format('Y-m-d') . ' 23:59:59')
            ->setParameter('direction', InvoiceDirection::OUTGOING)
            ->setParameter('statuses', [DocumentStatus::ISSUED, DocumentStatus::VALIDATED, DocumentStatus::SYNCED, DocumentStatus::SENT_TO_PROVIDER])
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Invoice[]
     */
    public function findOverdueByDays(int $days): array
    {
        $targetDate = new \DateTime(sprintf('-%d days', $days));

        return $this->createQueryBuilder('i')
            ->leftJoin('i.company', 'c')->addSelect('c')
            ->leftJoin('i.client', 'cl')->addSelect('cl')
            ->where('i.dueDate >= :start')
            ->andWhere('i.dueDate < :end')
            ->andWhere('i.paidAt IS NULL')
            ->andWhere('i.direction = :direction')
            ->andWhere('i.status IN (:statuses)')
            ->andWhere('c.syncEnabled = true')
            ->setParameter('start', $targetDate->format('Y-m-d') . ' 00:00:00')
            ->setParameter('end', $targetDate->format('Y-m-d') . ' 23:59:59')
            ->setParameter('direction', InvoiceDirection::OUTGOING)
            ->setParameter('statuses', [DocumentStatus::ISSUED, DocumentStatus::VALIDATED, DocumentStatus::SYNCED, DocumentStatus::SENT_TO_PROVIDER])
            ->getQuery()
            ->getResult();
    }

    /**
     * Count outgoing invoices created this calendar month for the given company.
     */
    public function countThisMonth(Company $company): int
    {
        $firstOfMonth = new \DateTimeImmutable('first day of this month midnight');

        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.company = :company')
            ->andWhere('i.direction = :direction')
            ->andWhere('i.createdAt >= :since')
            ->setParameter('company', $company)
            ->setParameter('direction', InvoiceDirection::OUTGOING)
            ->setParameter('since', $firstOfMonth)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
