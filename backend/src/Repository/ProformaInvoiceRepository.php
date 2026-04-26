<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\ProformaInvoice;
use App\Enum\ProformaStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProformaInvoice>
 */
class ProformaInvoiceRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
    ) {
        parent::__construct($registry, ProformaInvoice::class);
    }

    public function findWithDetails(string $uuid): ?ProformaInvoice
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.client', 'c')->addSelect('c')
            ->leftJoin('p.company', 'co')->addSelect('co')
            ->leftJoin('p.lines', 'pl')->addSelect('pl')
            ->leftJoin('p.convertedInvoice', 'ci')->addSelect('ci')
            ->where('p.id = :id')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('id', $uuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return ProformaInvoice[]
     */
    public function findExpired(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.validUntil < :today')
            ->andWhere('p.status IN (:statuses)')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('today', new \DateTime('today'))
            ->setParameter('statuses', [ProformaStatus::SENT, ProformaStatus::ACCEPTED])
            ->getQuery()
            ->getResult();
    }

    /**
     * @return ProformaInvoice[]
     */
    public function findExpiringSoon(int $days): array
    {
        $targetDate = new \DateTime("+{$days} days");

        return $this->createQueryBuilder('p')
            ->where('p.validUntil = :targetDate')
            ->andWhere('p.status IN (:statuses)')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('targetDate', $targetDate->format('Y-m-d'))
            ->setParameter('statuses', [ProformaStatus::SENT, ProformaStatus::ACCEPTED])
            ->getQuery()
            ->getResult();
    }

    private const SORTABLE_COLUMNS = ['number', 'issueDate', 'total', 'status', 'createdAt', 'validUntil'];

    public function findByCompanyPaginated(Company $company, array $filters = [], int $page = 1, int $limit = 20): array
    {
        $sortField = 'createdAt';
        $sortOrder = 'DESC';

        if (isset($filters['sort']) && in_array($filters['sort'], self::SORTABLE_COLUMNS, true)) {
            $sortField = $filters['sort'];
        }
        if (isset($filters['order']) && in_array(strtoupper($filters['order']), ['ASC', 'DESC'], true)) {
            $sortOrder = strtoupper($filters['order']);
        }

        $currency = $filters['currency'] ?? $company->getDefaultCurrency() ?? 'RON';

        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.client', 'c')->addSelect('c')
            ->where('p.company = :company')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->orderBy('p.' . $sortField, $sortOrder);

        $this->applyFilters($qb, $filters);

        $qb->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        $paginator = new Paginator($qb);

        // Aggregated totals (same currency, no conversion)
        $totalsQb = $this->createQueryBuilder('t')
            ->select(
                'COALESCE(SUM(t.subtotal), 0) AS totalExcluding',
                'COALESCE(SUM(t.vatTotal), 0) AS totalVat',
                'COALESCE(SUM(t.total), 0) AS totalIncluding',
            )
            ->leftJoin('t.client', 'c')
            ->where('t.company = :company')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('company', $company);

        $this->applyFilters($totalsQb, $filters);

        $totals = $totalsQb->getQuery()->getSingleResult();

        // Distinct currencies for this company
        $distinctCurrencies = $this->createQueryBuilder('dc')
            ->select('DISTINCT dc.currency')
            ->where('dc.company = :company')
            ->andWhere('dc.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->getQuery()
            ->getSingleColumnResult();

        return [
            'data' => iterator_to_array($paginator),
            'total' => count($paginator),
            'page' => $page,
            'limit' => $limit,
            'currency' => $currency,
            'distinctCurrencies' => $distinctCurrencies,
            'totals' => [
                'subtotal' => $totals['totalExcluding'] ?? '0.00',
                'vatTotal' => $totals['totalVat'] ?? '0.00',
                'total' => $totals['totalIncluding'] ?? '0.00',
            ],
        ];
    }

    private function applyFilters(\Doctrine\ORM\QueryBuilder $qb, array $filters): void
    {
        $a = $qb->getRootAliases()[0];

        if (isset($filters['currency'])) {
            $qb->andWhere("$a.currency = :currency")
                ->setParameter('currency', $filters['currency']);
        }

        if (isset($filters['status'])) {
            $qb->andWhere("$a.status = :status")
                ->setParameter('status', ProformaStatus::from($filters['status']));
        }

        if (isset($filters['search'])) {
            $qb->andWhere("$a.number LIKE :search OR c.name LIKE :search")
                ->setParameter('search', '%' . $filters['search'] . '%');
        }

        if (isset($filters['dateFrom'])) {
            $qb->andWhere("$a.issueDate >= :dateFrom")
                ->setParameter('dateFrom', new \DateTime($filters['dateFrom']));
        }

        if (isset($filters['dateTo'])) {
            $qb->andWhere("$a.issueDate <= :dateTo")
                ->setParameter('dateTo', new \DateTime($filters['dateTo']));
        }

        if (isset($filters['convertedToInvoice']) && in_array($filters['convertedToInvoice'], ['yes', 'no'], true)) {
            if ($filters['convertedToInvoice'] === 'yes') {
                $qb->andWhere("$a.convertedInvoice IS NOT NULL");
            } else {
                $qb->andWhere("$a.convertedInvoice IS NULL");
            }
        }

        if (isset($filters['expired']) && in_array($filters['expired'], ['yes', 'no'], true)) {
            if ($filters['expired'] === 'yes') {
                $qb->andWhere("$a.validUntil IS NOT NULL AND $a.validUntil < :today")
                    ->setParameter('today', new \DateTime('today'));
            } else {
                $qb->andWhere("$a.validUntil IS NULL OR $a.validUntil >= :today")
                    ->setParameter('today', new \DateTime('today'));
            }
        }
    }
}
