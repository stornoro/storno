<?php

namespace App\Repository;

use App\Entity\LicenseKey;
use App\Entity\Organization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LicenseKey>
 */
class LicenseKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LicenseKey::class);
    }

    public function findByKey(string $licenseKey): ?LicenseKey
    {
        return $this->findOneBy(['licenseKey' => $licenseKey, 'active' => true]);
    }

    /**
     * @return LicenseKey[]
     */
    public function findByOrganization(Organization $org): array
    {
        return $this->findBy(['organization' => $org], ['createdAt' => 'DESC']);
    }
}
