<?php

namespace App\Repository;

use App\Entity\StripeAppDeviceCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StripeAppDeviceCode>
 */
class StripeAppDeviceCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StripeAppDeviceCode::class);
    }

    public function findByDeviceCode(string $deviceCode): ?StripeAppDeviceCode
    {
        return $this->findOneBy(['deviceCode' => $deviceCode]);
    }

    public function findByUserCode(string $userCode): ?StripeAppDeviceCode
    {
        return $this->findOneBy(['userCode' => strtoupper($userCode)]);
    }
}
