<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use App\Entity\User;
use App\Enum\OrganizationRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrganizationMembership>
 */
class OrganizationMembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrganizationMembership::class);
    }

    public function findByUserAndOrganization(User $user, Organization $organization): ?OrganizationMembership
    {
        return $this->findOneBy([
            'user' => $user,
            'organization' => $organization,
            'isActive' => true,
        ]);
    }

    /**
     * @return OrganizationMembership[]
     */
    public function findActiveByUser(User $user): array
    {
        return $this->createQueryBuilder('om')
            ->leftJoin('om.organization', 'o')->addSelect('o')
            ->where('om.user = :user')
            ->andWhere('om.isActive = true')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return User[]
     */
    public function findActiveUsersByCompany(Company $company): array
    {
        return $this->getEntityManager()
            ->createQuery(
                'SELECT DISTINCT u FROM App\Entity\User u
                 JOIN u.organizationMemberships om
                 WHERE om.organization = :org
                   AND om.isActive = true
                   AND u.active = true
                   AND (
                       om.role IN (:unrestrictedRoles)
                       OR SIZE(om.allowedCompanies) = 0
                       OR :company MEMBER OF om.allowedCompanies
                   )'
            )
            ->setParameter('org', $company->getOrganization())
            ->setParameter('company', $company)
            ->setParameter('unrestrictedRoles', [OrganizationRole::OWNER, OrganizationRole::ADMIN])
            ->getResult();
    }
}
