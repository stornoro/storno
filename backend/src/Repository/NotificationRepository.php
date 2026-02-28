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
