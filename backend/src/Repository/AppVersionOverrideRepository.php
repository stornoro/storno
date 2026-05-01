<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AppVersionOverride;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AppVersionOverride>
 */
class AppVersionOverrideRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppVersionOverride::class);
    }

    public function findByPlatform(string $platform): ?AppVersionOverride
    {
        return $this->findOneBy(['platform' => $platform]);
    }

    /**
     * @return array<string, AppVersionOverride>  keyed by platform
     */
    public function findAllIndexed(): array
    {
        $rows = $this->findAll();
        $out = [];
        foreach ($rows as $row) {
            $out[$row->getPlatform()] = $row;
        }
        return $out;
    }
}
