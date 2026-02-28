<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\EmailUnsubscribe;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailUnsubscribe>
 */
class EmailUnsubscribeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailUnsubscribe::class);
    }

    public function isUnsubscribed(string $email, ?Company $company = null): bool
    {
        $qb = $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.email = :email')
            ->setParameter('email', $email);

        if ($company) {
            $qb->andWhere('u.company = :company')
                ->setParameter('company', $company);
        } else {
            $qb->andWhere('u.company IS NULL');
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
