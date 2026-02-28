<?php

namespace App\Repository;

use App\Entity\BorderouTransaction;
use App\Entity\Company;
use App\Entity\ImportJob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BorderouTransaction>
 */
class BorderouTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BorderouTransaction::class);
    }

    /**
     * @return BorderouTransaction[]
     */
    public function findByImportJob(ImportJob $job, array $filters = []): array
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.importJob = :job')
            ->setParameter('job', $job)
            ->orderBy('t.transactionDate', 'DESC');

        $this->applyFilters($qb, $filters);

        return $qb->getQuery()->getResult();
    }

    public function findByCompanyPaginated(Company $company, array $filters = [], int $page = 1, int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.company = :company')
            ->setParameter('company', $company)
            ->orderBy('t.transactionDate', 'DESC');

        $this->applyFilters($qb, $filters);

        $qb->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        $paginator = new Paginator($qb);

        return [
            'data' => iterator_to_array($paginator),
            'total' => count($paginator),
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * @return array{total: int, certain: int, attention: int, noMatch: int}
     */
    public function countByImportJobGroupedByStatus(ImportJob $job): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $jobId = $job->getId()->toRfc4122();

        $rows = $conn->fetchAllAssociative(
            'SELECT match_confidence, COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS total_amount
             FROM borderou_transaction
             WHERE import_job_id = ?
             GROUP BY match_confidence',
            [$jobId]
        );

        $result = ['total' => 0, 'certain' => 0, 'attention' => 0, 'noMatch' => 0, 'totalAmount' => '0.00'];
        $totalAmount = '0.00';

        foreach ($rows as $row) {
            $count = (int) $row['cnt'];
            $result['total'] += $count;
            $totalAmount = bcadd($totalAmount, $row['total_amount'], 2);

            match ($row['match_confidence']) {
                'certain' => $result['certain'] = $count,
                'attention' => $result['attention'] = $count,
                'no_match' => $result['noMatch'] = $count,
                default => null,
            };
        }

        $result['totalAmount'] = $totalAmount;

        return $result;
    }

    /**
     * @return BorderouTransaction[]
     */
    public function findByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return $this->createQueryBuilder('t')
            ->where('t.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find a single duplicate transaction: bankReference first, then date+amount+explanation composite.
     */
    public function findDuplicate(Company $company, ?string $bankReference, \DateTimeInterface $date, string $amount, ?string $explanation): ?BorderouTransaction
    {
        // 1. Check bankReference (most reliable for bank statements)
        if ($bankReference) {
            $existing = $this->createQueryBuilder('t')
                ->where('t.company = :company')
                ->andWhere('t.bankReference = :bankRef')
                ->setParameter('company', $company)
                ->setParameter('bankRef', $bankReference)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($existing) {
                return $existing;
            }
        }

        // 2. Fallback: date + amount + explanation composite
        $qb = $this->createQueryBuilder('t')
            ->where('t.company = :company')
            ->andWhere('t.transactionDate = :date')
            ->andWhere('t.amount = :amount')
            ->setParameter('company', $company)
            ->setParameter('date', $date)
            ->setParameter('amount', $amount);

        if ($explanation) {
            $qb->andWhere('t.explanation = :explanation')
                ->setParameter('explanation', $explanation);
        } else {
            $qb->andWhere('t.explanation IS NULL');
        }

        return $qb->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    public function findDuplicates(Company $company, \DateTimeInterface $date, string $amount, ?string $explanation): array
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.company = :company')
            ->andWhere('t.transactionDate = :date')
            ->andWhere('t.amount = :amount')
            ->setParameter('company', $company)
            ->setParameter('date', $date)
            ->setParameter('amount', $amount);

        if ($explanation) {
            $qb->andWhere('t.explanation = :explanation')
                ->setParameter('explanation', $explanation);
        }

        return $qb->getQuery()->getResult();
    }

    private function applyFilters($qb, array $filters): void
    {
        if (!empty($filters['importJobId'])) {
            $qb->andWhere('t.importJob = :importJobId')
                ->setParameter('importJobId', $filters['importJobId']);
        }

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $qb->andWhere('t.status = :status')
                ->setParameter('status', $filters['status']);
        }

        if (!empty($filters['confidence']) && $filters['confidence'] !== 'all') {
            $qb->andWhere('t.matchConfidence = :confidence')
                ->setParameter('confidence', $filters['confidence']);
        }

        if (!empty($filters['sourceType'])) {
            $qb->andWhere('t.sourceType = :sourceType')
                ->setParameter('sourceType', $filters['sourceType']);
        }

        if (!empty($filters['sourceProvider'])) {
            $qb->andWhere('t.sourceProvider = :sourceProvider')
                ->setParameter('sourceProvider', $filters['sourceProvider']);
        }

        if (!empty($filters['dateFrom'])) {
            $qb->andWhere('t.transactionDate >= :dateFrom')
                ->setParameter('dateFrom', new \DateTime($filters['dateFrom']));
        }

        if (!empty($filters['dateTo'])) {
            $qb->andWhere('t.transactionDate <= :dateTo')
                ->setParameter('dateTo', new \DateTime($filters['dateTo']));
        }
    }
}
