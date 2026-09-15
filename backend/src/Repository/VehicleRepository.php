<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\Vehicle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Vehicle> */
class VehicleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Vehicle::class);
    }

    /** @return list<Vehicle> */
    public function findForCompany(Company $company, ?bool $active = null, ?string $search = null): array
    {
        $qb = $this->createQueryBuilder('v')
            ->andWhere('v.company = :company')->setParameter('company', $company)
            ->orderBy('v.active', 'DESC')
            ->addOrderBy('v.plate', 'ASC');
        if ($active !== null) {
            $qb->andWhere('v.active = :active')->setParameter('active', $active);
        }
        if ($search !== null && trim($search) !== '') {
            $qb->andWhere('v.plate LIKE :q OR v.make LIKE :q OR v.model LIKE :q OR v.driverName LIKE :q OR v.vin LIKE :q')
                ->setParameter('q', '%' . trim($search) . '%');
        }

        return $qb->getQuery()->getResult();
    }
}
