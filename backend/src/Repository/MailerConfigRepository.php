<?php

namespace App\Repository;

use App\Entity\MailerConfig;
use App\Entity\Organization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MailerConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailerConfig::class);
    }

    public function findByOrganization(Organization $organization): ?MailerConfig
    {
        return $this->findOneBy(['organization' => $organization]);
    }
}
