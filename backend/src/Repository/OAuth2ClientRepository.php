<?php

namespace App\Repository;

use App\Entity\OAuth2Client;
use App\Entity\Organization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OAuth2Client>
 */
class OAuth2ClientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OAuth2Client::class);
    }

    public function findByClientId(string $clientId): ?OAuth2Client
    {
        return $this->findOneBy(['clientId' => $clientId]);
    }

    /**
     * @return OAuth2Client[]
     */
    public function findByOrganization(Organization $org): array
    {
        return $this->findBy(['organization' => $org], ['createdAt' => 'DESC']);
    }
}
