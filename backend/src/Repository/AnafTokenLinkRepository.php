<?php

namespace App\Repository;

use App\Entity\AnafTokenLink;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnafTokenLink>
 */
class AnafTokenLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnafTokenLink::class);
    }

    public function countActiveByUser(User $user): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.user = :user')
            ->andWhere('l.expiresAt > :now')
            ->andWhere('l.usedAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return AnafTokenLink[]
     */
    public function findActiveByUser(User $user): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.user = :user')
            ->andWhere('l.expiresAt > :now')
            ->andWhere('l.usedAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('l.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findValidByToken(string $token): ?AnafTokenLink
    {
        return $this->createQueryBuilder('l')
            ->where('l.token = :token')
            ->andWhere('l.expiresAt > :now')
            ->andWhere('l.usedAt IS NULL')
            ->setParameter('token', $token)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getOneOrNullResult();
    }
}
