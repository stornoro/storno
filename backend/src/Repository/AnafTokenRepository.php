<?php

namespace App\Repository;

use App\Entity\AnafToken;
use App\Entity\Organization;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnafToken>
 */
class AnafTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnafToken::class);
    }

    /**
     * @return AnafToken[]
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.user = :user')
            ->setParameter('user', $user)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return AnafToken[]
     */
    public function findByOrganization(Organization $organization): array
    {
        return $this->createQueryBuilder('t')
            ->innerJoin('t.user', 'u')
            ->innerJoin('u.organizationMemberships', 'm')
            ->where('m.organization = :org')
            ->setParameter('org', $organization)
            ->andWhere('t.token IS NOT NULL')
            ->orderBy('t.expireAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return AnafToken[]
     */
    public function findExpiringWithin(\DateTimeImmutable $threshold): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.expireAt <= :threshold')
            ->andWhere('t.refreshToken IS NOT NULL')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getResult();
    }
}
