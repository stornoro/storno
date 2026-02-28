<?php

namespace App\Repository;

use App\Entity\UserBackupCode;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class UserBackupCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserBackupCode::class);
    }

    public function countUnusedByUser(User $user): int
    {
        return $this->count(['user' => $user, 'used' => false]);
    }

    public function findUnusedByUser(User $user): array
    {
        return $this->findBy(['user' => $user, 'used' => false]);
    }

    public function deleteAllByUser(User $user): void
    {
        $this->createQueryBuilder('b')
            ->delete()
            ->where('b.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}
