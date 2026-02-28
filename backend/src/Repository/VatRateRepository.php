<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\VatRate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VatRate>
 */
class VatRateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VatRate::class);
    }

    public function findByCompany(Company $company): array
    {
        return $this->createQueryBuilder('vr')
            ->where('vr.company = :company')
            ->andWhere('vr.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->orderBy('vr.position', 'ASC')
            ->addOrderBy('vr.rate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findActiveByCompany(Company $company): array
    {
        return $this->createQueryBuilder('vr')
            ->where('vr.company = :company')
            ->andWhere('vr.isActive = true')
            ->andWhere('vr.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->orderBy('vr.position', 'ASC')
            ->addOrderBy('vr.rate', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
