<?php

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\WhiteLabelConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WhiteLabelConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WhiteLabelConfig::class);
    }

    public function findByOrganization(Organization $organization): ?WhiteLabelConfig
    {
        return $this->findOneBy(['organization' => $organization]);
    }

    /**
     * A verified, enabled custom domain → its config (used for CORS + host routing).
     */
    public function findVerifiedByCustomDomain(string $domain): ?WhiteLabelConfig
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.customDomain = :domain')
            ->andWhere('w.customDomainVerifiedAt IS NOT NULL')
            ->andWhere('w.enabled = true')
            ->setParameter('domain', $domain)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
