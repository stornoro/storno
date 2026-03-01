<?php

namespace App\Repository;

use App\Entity\OAuth2Client;
use App\Entity\OAuth2RefreshToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OAuth2RefreshToken>
 */
class OAuth2RefreshTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OAuth2RefreshToken::class);
    }

    public function findOneByHash(string $tokenHash): ?OAuth2RefreshToken
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    /**
     * Revoke all refresh tokens in the same family (replay detection).
     */
    public function revokeFamily(string $family): int
    {
        return $this->createQueryBuilder('t')
            ->update()
            ->set('t.revokedAt', ':now')
            ->where('t.family = :family')
            ->andWhere('t.revokedAt IS NULL')
            ->setParameter('family', $family)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->execute();
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
