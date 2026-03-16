<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\TaxDeclaration;
use App\Enum\DeclarationStatus;
use App\Enum\DeclarationType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TaxDeclaration>
 */
class TaxDeclarationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaxDeclaration::class);
    }

    public function findByCompanyPaginated(Company $company, array $filters = [], int $page = 1, int $limit = 10): array
    {
        $qb = $this->createQueryBuilder('d')
            ->where('d.company = :company')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->orderBy('d.createdAt', 'DESC');

        if (!empty($filters['type'])) {
            $qb->andWhere('d.type = :type')
                ->setParameter('type', DeclarationType::from($filters['type']));
        }

        if (!empty($filters['status'])) {
            $qb->andWhere('d.status = :status')
                ->setParameter('status', DeclarationStatus::from($filters['status']));
        }

        if (!empty($filters['year'])) {
            $qb->andWhere('d.year = :year')
                ->setParameter('year', (int) $filters['year']);
        }

        if (!empty($filters['month'])) {
            $qb->andWhere('d.month = :month')
                ->setParameter('month', (int) $filters['month']);
        }

        $total = (clone $qb)
            ->select('COUNT(d.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $items = $qb
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    public function findByPeriod(Company $company, DeclarationType $type, int $year, int $month): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.company = :company')
            ->andWhere('d.type = :type')
            ->andWhere('d.year = :year')
            ->andWhere('d.month = :month')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->setParameter('type', $type)
            ->setParameter('year', $year)
            ->setParameter('month', $month)
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param DeclarationStatus[] $statuses
     * @return TaxDeclaration[]
     */
    public function findByCompanyAndStatuses(Company $company, array $statuses): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.company = :company')
            ->andWhere('d.status IN (:statuses)')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->setParameter('statuses', $statuses)
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findExisting(Company $company, DeclarationType $type, int $year, int $month): ?TaxDeclaration
    {
        return $this->createQueryBuilder('d')
            ->where('d.company = :company')
            ->andWhere('d.type = :type')
            ->andWhere('d.year = :year')
            ->andWhere('d.month = :month')
            ->andWhere('d.deletedAt IS NULL')
            ->andWhere('d.status NOT IN (:terminalStatuses)')
            ->setParameter('company', $company)
            ->setParameter('type', $type)
            ->setParameter('year', $year)
            ->setParameter('month', $month)
            ->setParameter('terminalStatuses', [DeclarationStatus::REJECTED, DeclarationStatus::ERROR])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
