<?php

namespace App\Repository;

use App\Entity\UserPasskey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class UserPasskeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserPasskey::class);
    }

    public function findOneByCredentialId(string $credentialId): ?UserPasskey
    {
        return $this->findOneBy(['credentialId' => $credentialId]);
    }
}
