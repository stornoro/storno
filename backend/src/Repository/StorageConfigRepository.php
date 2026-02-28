<?php

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\StorageConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StorageConfig>
 */
class StorageConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StorageConfig::class);
    }

    public function findByOrganization(Organization $organization): ?StorageConfig
    {
        return $this->findOneBy(['organization' => $organization]);
    }
}
