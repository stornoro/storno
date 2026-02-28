<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\EmailTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailTemplate>
 */
class EmailTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailTemplate::class);
    }

    public function findByCompany(Company $company): array
    {
        return $this->createQueryBuilder('et')
            ->where('et.company = :company')
            ->setParameter('company', $company)
            ->orderBy('et.isDefault', 'DESC')
            ->addOrderBy('et.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findDefaultForCompany(Company $company): ?EmailTemplate
    {
        return $this->createQueryBuilder('et')
            ->where('et.company = :company')
            ->andWhere('et.isDefault = true')
            ->setParameter('company', $company)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
