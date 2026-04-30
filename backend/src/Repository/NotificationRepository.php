<?php

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;

/**
 * @extends ServiceEntityRepository<Notification>
 *
 * @method Notification|null find($id, $lockMode = null, $lockVersion = null)
 * @method Notification|null findOneBy(array $criteria, array $orderBy = null)
 * @method Notification[]    findAll()
 * @method Notification[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }


    public function markRead(User $user): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $this->createQueryBuilder('n')
            ->update(Notification::class, 'n')
            ->set('n.isRead', true)
            ->where('n.user = :user')->setParameter('user', $user)
            ->andWhere('n.isRead = false')
            ->getQuery()
            ->execute();
    }

    public function findLatest(int $offset = 0, User $user): array
    {
        return $this->createQueryBuilder('n')
            ->orderBy('n.sentAt', 'DESC')
            ->andWhere('n.user = :user')->setParameter('user', $user)
            ->setFirstResult($offset)
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
    }

    public function findPaginated(User $user, int $page = 1, int $limit = 20): array
    {
        $offset = ($page - 1) * $limit;

        $qb = $this->createQueryBuilder('n')
            ->andWhere('n.user = :user')->setParameter('user', $user)
            ->orderBy('n.sentAt', 'DESC');

        $total = (int) (clone $qb)->select('COUNT(n.id)')->getQuery()->getSingleScalarResult();

        $items = $qb
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    public function countUnread(User $user): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.user = :user')->setParameter('user', $user)
            ->andWhere('n.isRead = false')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Delete read notifications older than {readMaxAgeDays} and any notification
     * older than {unreadMaxAgeDays}. Returns the number of rows deleted.
     */
    public function deleteOlderThan(int $readMaxAgeDays, int $unreadMaxAgeDays): int
    {
        $now = new \DateTimeImmutable();
        $readCutoff = $now->modify(sprintf('-%d days', $readMaxAgeDays));
        $hardCutoff = $now->modify(sprintf('-%d days', $unreadMaxAgeDays));

        $deleted = (int) $this->createQueryBuilder('n')
            ->delete()
            ->where('n.isRead = true AND n.sentAt < :readCutoff')
            ->orWhere('n.sentAt < :hardCutoff')
            ->setParameter('readCutoff', $readCutoff)
            ->setParameter('hardCutoff', $hardCutoff)
            ->getQuery()
            ->execute();

        return $deleted;
    }

    /**
     * For each user with more than {maxPerUser} notifications, delete the oldest
     * rows above the cap. Returns the total number of rows deleted.
     *
     * Uses raw SQL because Doctrine ORM's DQL doesn't support correlated subqueries
     * in DELETE on MySQL.
     */
    public function deletePerUserOverflow(int $maxPerUser): int
    {
        $conn = $this->getEntityManager()->getConnection();

        // Find user_ids whose notification count exceeds the cap.
        $rows = $conn->fetchAllAssociative(
            'SELECT user_id, COUNT(id) AS c FROM notification GROUP BY user_id HAVING c > :max',
            ['max' => $maxPerUser],
        );

        $totalDeleted = 0;
        foreach ($rows as $row) {
            $userId = $row['user_id'];
            $count = (int) $row['c'];
            $excess = $count - $maxPerUser;
            if ($excess <= 0) {
                continue;
            }

            // Delete the {excess} oldest rows for this user.
            $deleted = $conn->executeStatement(
                'DELETE FROM notification WHERE user_id = :user ORDER BY sent_at ASC LIMIT ' . $excess,
                ['user' => $userId],
            );
            $totalDeleted += (int) $deleted;
        }

        return $totalDeleted;
    }

    //    public function findOneBySomeField($value): ?Notification
    //    {
    //        return $this->createQueryBuilder('n')
    //            ->andWhere('n.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
