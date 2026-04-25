<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\User;
use App\Entity\UserDashboardConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserDashboardConfig>
 */
class UserDashboardConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserDashboardConfig::class);
    }

    public function findByUserAndCompany(User $user, Company $company): ?UserDashboardConfig
    {
        return $this->createQueryBuilder('c')
            ->where('c.user = :user')
            ->andWhere('c.company = :company')
            ->setParameter('user', $user)
            ->setParameter('company', $company)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
