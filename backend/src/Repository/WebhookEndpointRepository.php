<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\WebhookEndpoint;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WebhookEndpoint>
 */
class WebhookEndpointRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebhookEndpoint::class);
    }

    public function findByCompany(Company $company): array
    {
        return $this->createQueryBuilder('we')
            ->where('we.company = :company')
            ->setParameter('company', $company)
            ->orderBy('we.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findActiveByCompanyAndEvent(Company $company, string $eventType): array
    {
        return $this->createQueryBuilder('we')
            ->where('we.company = :company')
            ->andWhere('we.isActive = true')
            ->setParameter('company', $company)
            ->getQuery()
            ->getResult();
    }
}
