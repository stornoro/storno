<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\DeliveryNote;
use App\Enum\DeliveryNoteStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DeliveryNote>
 */
class DeliveryNoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeliveryNote::class);
    }

    public function findWithDetails(string $uuid): ?DeliveryNote
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.client', 'c')->addSelect('c')
            ->leftJoin('d.company', 'co')->addSelect('co')
            ->leftJoin('d.lines', 'dl')->addSelect('dl')
            ->leftJoin('d.convertedInvoice', 'ci')->addSelect('ci')
            ->where('d.id = :id')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('id', $uuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private const SORTABLE_COLUMNS = ['number', 'issueDate', 'total', 'status', 'createdAt'];

    public function findRecentByClient(Company $company, string $clientId, int $limit = 5): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.company = :company')
            ->andWhere('d.client = :clientId')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->setParameter('clientId', $clientId)
            ->orderBy('d.issueDate', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByClient(Company $company, string $clientId): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.company = :company')
            ->andWhere('d.client = :clientId')
            ->andWhere('d.deletedAt IS NULL')
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

        $qb = $this->createQueryBuilder('d')
            ->leftJoin('d.client', 'c')->addSelect('c')
            ->where('d.company = :company')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->orderBy('d.' . $sortField, $sortOrder);

        if (isset($filters['status'])) {
            $qb->andWhere('d.status = :status')
                ->setParameter('status', DeliveryNoteStatus::from($filters['status']));
        }

        if (isset($filters['search'])) {
            $qb->andWhere('d.number LIKE :search OR c.name LIKE :search')
                ->setParameter('search', '%' . $filters['search'] . '%');
        }

        if (isset($filters['dateFrom'])) {
            $qb->andWhere('d.issueDate >= :dateFrom')
                ->setParameter('dateFrom', new \DateTime($filters['dateFrom']));
        }

        if (isset($filters['dateTo'])) {
            $qb->andWhere('d.issueDate <= :dateTo')
                ->setParameter('dateTo', new \DateTime($filters['dateTo']));
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
}
