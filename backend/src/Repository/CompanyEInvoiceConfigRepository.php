<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\CompanyEInvoiceConfig;
use App\Enum\EInvoiceProvider;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CompanyEInvoiceConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompanyEInvoiceConfig::class);
    }

    /**
     * @return CompanyEInvoiceConfig[]
     */
    public function findByCompany(Company $company): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.company = :company')
            ->setParameter('company', $company)
            ->orderBy('c.provider', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByCompanyAndProvider(Company $company, EInvoiceProvider $provider): ?CompanyEInvoiceConfig
    {
        return $this->createQueryBuilder('c')
            ->where('c.company = :company')
            ->andWhere('c.provider = :provider')
            ->setParameter('company', $company)
            ->setParameter('provider', $provider)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return CompanyEInvoiceConfig[]
     */
    public function findEnabledByProvider(EInvoiceProvider $provider): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.provider = :provider')
            ->andWhere('c.enabled = true')
            ->setParameter('provider', $provider)
            ->getQuery()
            ->getResult();
    }
}
