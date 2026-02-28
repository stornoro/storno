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
    public function __construct(ManagerRegistry $registry)
    {
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

        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.client', 'c')->addSelect('c')
            ->where('p.company = :company')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->orderBy('p.' . $sortField, $sortOrder);

        if (isset($filters['status'])) {
            $qb->andWhere('p.status = :status')
                ->setParameter('status', ProformaStatus::from($filters['status']));
        }

        if (isset($filters['search'])) {
            $qb->andWhere('p.number LIKE :search OR c.name LIKE :search')
                ->setParameter('search', '%' . $filters['search'] . '%');
        }

        if (isset($filters['dateFrom'])) {
            $qb->andWhere('p.issueDate >= :dateFrom')
                ->setParameter('dateFrom', new \DateTime($filters['dateFrom']));
        }

        if (isset($filters['dateTo'])) {
            $qb->andWhere('p.issueDate <= :dateTo')
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
