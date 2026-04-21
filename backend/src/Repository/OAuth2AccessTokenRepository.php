<?php

namespace App\Repository;

use App\Entity\OAuth2AccessToken;
use App\Entity\OAuth2Client;
use App\Entity\User;
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

    public function revokeAllForUserAndClient(User $user, OAuth2Client $client): int
    {
        return $this->createQueryBuilder('t')
            ->update()
            ->set('t.revokedAt', ':now')
            ->where('t.user = :user')
            ->andWhere('t.client = :client')
            ->andWhere('t.revokedAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('client', $client)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->execute();
    }

    /**
     * Return the set of OAuth2 clients this user has at least one non-revoked token for,
     * with the most recent token activity per client so the UI can sort by "last used".
     *
     * @return array<int, array{client: OAuth2Client, lastActiveAt: \DateTimeImmutable, scopes: array<string>}>
     */
    public function findAuthorizedClientsForUser(User $user): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('IDENTITY(t.client) AS clientId, MAX(t.createdAt) AS lastActiveAt')
            ->where('t.user = :user')
            ->andWhere('t.revokedAt IS NULL')
            ->andWhere('t.expiresAt > :now')
            ->groupBy('t.client')
            ->setParameter('user', $user)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getArrayResult();

        if (empty($rows)) {
            return [];
        }

        $clientIds = array_column($rows, 'clientId');
        $clients = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('c')
            ->from(OAuth2Client::class, 'c')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $clientIds)
            ->getQuery()
            ->getResult();

        $clientsById = [];
        foreach ($clients as $c) {
            $clientsById[$c->getId()->toRfc4122()] = $c;
        }

        // Latest granted scopes per client (from the most recent non-revoked token)
        $scopesRows = $this->createQueryBuilder('t')
            ->select('IDENTITY(t.client) AS clientId, t.scopes AS scopes, t.createdAt AS createdAt')
            ->where('t.user = :user')
            ->andWhere('t.revokedAt IS NULL')
            ->andWhere('t.client IN (:ids)')
            ->orderBy('t.createdAt', 'DESC')
            ->setParameter('user', $user)
            ->setParameter('ids', $clientIds)
            ->getQuery()
            ->getArrayResult();

        $scopesByClient = [];
        foreach ($scopesRows as $row) {
            $cid = $row['clientId'];
            if (!isset($scopesByClient[$cid])) {
                $scopesByClient[$cid] = $row['scopes'] ?? [];
            }
        }

        $result = [];
        foreach ($rows as $row) {
            $cid = $row['clientId'];
            if (!isset($clientsById[$cid])) {
                continue;
            }
            $result[] = [
                'client' => $clientsById[$cid],
                'lastActiveAt' => $row['lastActiveAt'] instanceof \DateTimeImmutable
                    ? $row['lastActiveAt']
                    : new \DateTimeImmutable((string) $row['lastActiveAt']),
                'scopes' => $scopesByClient[$cid] ?? [],
            ];
        }

        usort($result, fn($a, $b) => $b['lastActiveAt'] <=> $a['lastActiveAt']);

        return $result;
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
