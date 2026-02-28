<?php

namespace App\Repository;

use App\Entity\UserBilling;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserBilling>
 *
 * @method UserBilling|null find($id, $lockMode = null, $lockVersion = null)
 * @method UserBilling|null findOneBy(array $criteria, array $orderBy = null)
 * @method UserBilling[]    findAll()
 * @method UserBilling[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class UserBillingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserBilling::class);
    }

//    /**
//     * @return UserBilling[] Returns an array of UserBilling objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('u')
//            ->andWhere('u.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('u.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?UserBilling
//    {
//        return $this->createQueryBuilder('u')
//            ->andWhere('u.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}
