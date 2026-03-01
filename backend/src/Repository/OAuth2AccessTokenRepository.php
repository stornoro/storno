<?php

namespace App\Repository;

use App\Entity\OAuth2AccessToken;
use App\Entity\OAuth2Client;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OAuth2AccessToken>
 */
class OAuth2AccessTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OAuth2AccessToken::class);
    }

    public function findOneByHash(string $tokenHash): ?OAuth2AccessToken
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    public function revokeAllForClient(OAuth2Client $client): int
    {
        return $this->createQueryBuilder('t')
            ->update()
            ->set('t.revokedAt', ':now')
            ->where('t.client = :client')
            ->andWhere('t.revokedAt IS NULL')
            ->setParameter('client', $client)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->execute();
    }

    /**
     * Purge expired or revoked tokens older than given threshold.
     */
    public function purgeExpired(\DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('t')
            ->delete()
            ->where('(t.expiresAt < :before OR (t.revokedAt IS NOT NULL AND t.revokedAt < :before))')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
