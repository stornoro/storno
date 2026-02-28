<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\DocumentSeries;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentSeries>
 */
class DocumentSeriesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentSeries::class);
    }

    public function findByCompany(Company $company): array
    {
        return $this->createQueryBuilder('ds')
            ->where('ds.company = :company')
            ->setParameter('company', $company)
            ->orderBy('ds.prefix', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByPrefix(Company $company, string $prefix): ?DocumentSeries
    {
        return $this->createQueryBuilder('ds')
            ->where('ds.company = :company')
            ->andWhere('ds.prefix = :prefix')
            ->setParameter('company', $company)
            ->setParameter('prefix', $prefix)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find the default series of the given type for a company.
     * Falls back to the first active series if none is marked as default.
     */
    public function findDefaultByType(Company $company, string $type): ?DocumentSeries
    {
        // Try explicit default first
        $default = $this->createQueryBuilder('ds')
            ->where('ds.company = :company')
            ->andWhere('ds.type = :type')
            ->andWhere('ds.active = true')
            ->andWhere('ds.isDefault = true')
            ->setParameter('company', $company)
            ->setParameter('type', $type)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($default) {
            return $default;
        }

        // Fallback to first active
        return $this->createQueryBuilder('ds')
            ->where('ds.company = :company')
            ->andWhere('ds.type = :type')
            ->andWhere('ds.active = true')
            ->setParameter('company', $company)
            ->setParameter('type', $type)
            ->orderBy('ds.createdAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Unset isDefault for all series of a given type for a company.
     */
    public function clearDefaultsForType(Company $company, string $type): void
    {
        $this->createQueryBuilder('ds')
            ->update()
            ->set('ds.isDefault', 'false')
            ->where('ds.company = :company')
            ->andWhere('ds.type = :type')
            ->andWhere('ds.isDefault = true')
            ->setParameter('company', $company)
            ->setParameter('type', $type)
            ->getQuery()
            ->execute();
    }
}
