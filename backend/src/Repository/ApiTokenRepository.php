<?php

namespace App\Repository;

use App\Entity\ApiToken;
use App\Entity\Organization;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiToken>
 *
 * @method ApiToken|null find($id, $lockMode = null, $lockVersion = null)
 * @method ApiToken|null findOneBy(array $criteria, array $orderBy = null)
 * @method ApiToken[]    findAll()
 * @method ApiToken[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ApiTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiToken::class);
    }

    public function findOneByHash(string $hash): ?ApiToken
    {
        return $this->findOneBy(['tokenHash' => $hash]);
    }

    /**
     * @return ApiToken[]
     */
    public function findByUser(User $user, ?Organization $org = null): array
    {
        $criteria = ['user' => $user];
        if ($org !== null) {
            $criteria['organization'] = $org;
        }

        return $this->findBy($criteria, ['createdAt' => 'DESC']);
    }

    /**
     * @return ApiToken[]
     */
    public function findByOrganization(Organization $org): array
    {
        return $this->findBy(['organization' => $org], ['createdAt' => 'DESC']);
    }
}
