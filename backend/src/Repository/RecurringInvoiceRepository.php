<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\RecurringInvoice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RecurringInvoice>
 */
class RecurringInvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecurringInvoice::class);
    }

    public function findWithDetails(string $uuid): ?RecurringInvoice
    {
        return $this->createQueryBuilder('ri')
            ->leftJoin('ri.client', 'c')->addSelect('c')
            ->leftJoin('ri.company', 'co')->addSelect('co')
            ->leftJoin('ri.lines', 'ril')->addSelect('ril')
            ->leftJoin('ri.documentSeries', 'ds')->addSelect('ds')
            ->where('ri.id = :id')
            ->andWhere('ri.deletedAt IS NULL')
            ->setParameter('id', $uuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByCompanyPaginated(Company $company, array $filters = [], int $page = 1, int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('ri')
            ->leftJoin('ri.client', 'c')->addSelect('c')
            ->where('ri.company = :company')
            ->andWhere('ri.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->orderBy('ri.createdAt', 'DESC');

        if (isset($filters['search']) && $filters['search'] !== '') {
            $qb->andWhere('ri.reference LIKE :search OR c.name LIKE :search')
                ->setParameter('search', '%' . $filters['search'] . '%');
        }

        if (isset($filters['isActive'])) {
            $qb->andWhere('ri.isActive = :isActive')
                ->setParameter('isActive', filter_var($filters['isActive'], FILTER_VALIDATE_BOOLEAN));
        }

        if (isset($filters['frequency'])) {
            $qb->andWhere('ri.frequency = :frequency')
                ->setParameter('frequency', $filters['frequency']);
        }

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
     * Find recurring invoices that are due for processing.
     *
     * @return RecurringInvoice[]
     */
    public function findDueForProcessing(\DateTimeInterface $date, int $limit = 100): array
    {
        return $this->createQueryBuilder('ri')
            ->leftJoin('ri.client', 'c')->addSelect('c')
            ->leftJoin('ri.lines', 'ril')->addSelect('ril')
            ->leftJoin('ri.company', 'co')->addSelect('co')
            ->leftJoin('ri.documentSeries', 'ds')->addSelect('ds')
            ->where('ri.isActive = true')
            ->andWhere('ri.deletedAt IS NULL')
            ->andWhere('ri.nextIssuanceDate <= :date')
            ->andWhere('ri.stopDate IS NULL OR ri.stopDate >= :date')
            ->setParameter('date', $date)
            ->orderBy('ri.nextIssuanceDate', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
