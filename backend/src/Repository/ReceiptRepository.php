<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\Receipt;
use App\Enum\ReceiptStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Receipt>
 */
class ReceiptRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
    ) {
        parent::__construct($registry, Receipt::class);
    }

    public function findWithDetails(string $uuid): ?Receipt
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.client', 'c')->addSelect('c')
            ->leftJoin('r.company', 'co')->addSelect('co')
            ->leftJoin('r.lines', 'rl')->addSelect('rl')
            ->leftJoin('r.convertedInvoice', 'ci')->addSelect('ci')
            ->where('r.id = :id')
            ->andWhere('r.deletedAt IS NULL')
            ->setParameter('id', $uuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private const SORTABLE_COLUMNS = ['number', 'issueDate', 'total', 'status', 'createdAt'];

    public function findRecentByClient(Company $company, string $clientId, int $limit = 5): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.company = :company')
            ->andWhere('r.client = :clientId')
            ->andWhere('r.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->setParameter('clientId', $clientId)
            ->orderBy('r.issueDate', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByClient(Company $company, string $clientId): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.company = :company')
            ->andWhere('r.client = :clientId')
            ->andWhere('r.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->setParameter('clientId', $clientId)
            ->getQuery()
            ->getSingleScalarResult();
    }

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

        $qb = $this->createQueryBuilder('r')
            ->leftJoin('r.client', 'c')->addSelect('c')
            ->where('r.company = :company')
            ->andWhere('r.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->orderBy('r.' . $sortField, $sortOrder);

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
                ->setParameter('status', ReceiptStatus::from($filters['status']));
        }

        if (isset($filters['search'])) {
            $qb->andWhere("$a.number LIKE :search OR c.name LIKE :search OR $a.customerName LIKE :search OR $a.fiscalNumber LIKE :search")
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
    }
}
