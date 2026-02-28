<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\TrialBalance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrialBalance>
 */
class TrialBalanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrialBalance::class);
    }

    /**
     * @return TrialBalance[]
     */
    public function findByCompanyAndYear(Company $company, int $year): array
    {
        return $this->createQueryBuilder('tb')
            ->andWhere('tb.company = :company')
            ->andWhere('tb.year = :year')
            ->andWhere('tb.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->setParameter('year', $year)
            ->orderBy('tb.month', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByCompanyAndContentHash(Company $company, string $contentHash): ?TrialBalance
    {
        return $this->createQueryBuilder('tb')
            ->andWhere('tb.company = :company')
            ->andWhere('tb.contentHash = :hash')
            ->andWhere('tb.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->setParameter('hash', $contentHash)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByCompanyYearMonth(Company $company, int $year, int $month): ?TrialBalance
    {
        return $this->createQueryBuilder('tb')
            ->andWhere('tb.company = :company')
            ->andWhere('tb.year = :year')
            ->andWhere('tb.month = :month')
            ->andWhere('tb.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->setParameter('year', $year)
            ->setParameter('month', $month)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
