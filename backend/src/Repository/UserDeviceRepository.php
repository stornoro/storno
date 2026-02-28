<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserDevice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserDevice>
 */
class UserDeviceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserDevice::class);
    }

    public function findByToken(string $token): ?UserDevice
    {
        return $this->findOneBy(['token' => $token]);
    }

    public function removeByToken(User $user, string $token): void
    {
        $this->createQueryBuilder('d')
            ->delete()
            ->where('d.user = :user')
            ->andWhere('d.token = :token')
            ->setParameter('user', $user)
            ->setParameter('token', $token)
            ->getQuery()
            ->execute();
    }
}
