<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use App\Enum\OrganizationRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use App\Doctrine\Type\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Company>
 *
 * @method Company|null find($id, $lockMode = null, $lockVersion = null)
 * @method Company|null findOneBy(array $criteria, array $orderBy = null)
 * @method Company[]    findAll()
 * @method Company[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CompanyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Company::class);
    }

    public function findByOrganizationAndCif(Organization $organization, int $cif): ?Company
    {
        return $this->findOneBy(['organization' => $organization, 'cif' => $cif]);
    }

    /**
     * @return Company[]
     */
    public function findByOrganizationAndMembership(Organization $organization, ?OrganizationMembership $membership): array
    {
        if (!$membership) {
            return $this->findBy(['organization' => $organization]);
        }

        $role = $membership->getRole();
        if ($role === OrganizationRole::OWNER || $role === OrganizationRole::ADMIN || $membership->hasAccessToAllCompanies()) {
            return $this->findBy(['organization' => $organization]);
        }

        $allowedCompanies = $membership->getAllowedCompanies();
        if ($allowedCompanies->isEmpty()) {
            return [];
        }

        return $this->createQueryBuilder('c')
            ->where('c.organization = :org')
            ->andWhere('c IN (:companies)')
            ->setParameter('org', $organization)
            ->setParameter('companies', $allowedCompanies->toArray())
            ->getQuery()
            ->getResult();
    }

    /**
     * Query builder for one id-ordered batch of companies (alias "c"), starting after $afterId.
     * Callers add their own conditions, run it, remember the last id and clear the entity
     * manager between batches. Soft-deleted companies are excluded by the soft_delete filter.
     */
    public function createBatchQueryBuilder(?Uuid $afterId, int $limit): QueryBuilder
    {
        $qb = $this->createQueryBuilder('c')
            ->orderBy('c.id', 'ASC')
            ->setMaxResults($limit);

        if ($afterId !== null) {
            $qb->andWhere('c.id > :afterId')->setParameter('afterId', $afterId, UuidType::NAME);
        }

        return $qb;
    }
}
