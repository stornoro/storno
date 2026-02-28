<?php

namespace App\Repository;

use App\Entity\StripeAppToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StripeAppToken>
 */
class StripeAppTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StripeAppToken::class);
    }

    public function findByStripeAccountId(string $stripeAccountId): ?StripeAppToken
    {
        return $this->findOneBy(['stripeAccountId' => $stripeAccountId]);
    }

    public function findValidByAccessToken(string $accessToken): ?StripeAppToken
    {
        return $this->createQueryBuilder('t')
            ->where('t.accessToken = :token')
            ->andWhere('t.expiresAt > :now')
            ->setParameter('token', $accessToken)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user]);
    }

    public function findByUserWithCompany(User $user): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.user = :user')
            ->andWhere('t.company IS NOT NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
    }
}
