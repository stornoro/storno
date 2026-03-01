<?php

namespace App\Repository;

use App\Entity\OAuth2AuthorizationCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OAuth2AuthorizationCode>
 */
class OAuth2AuthorizationCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OAuth2AuthorizationCode::class);
    }

    public function findOneByCodeHash(string $codeHash): ?OAuth2AuthorizationCode
    {
        return $this->findOneBy(['codeHash' => $codeHash]);
    }

    /**
     * Purge expired authorization codes older than given threshold.
     */
    public function purgeExpired(\DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('c')
            ->delete()
            ->where('c.expiresAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
