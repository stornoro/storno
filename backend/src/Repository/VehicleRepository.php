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

    /** The active or archived vehicle of the company with this plate, ignoring $exclude. */
    public function findOneByPlate(Company $company, string $plate, ?Vehicle $exclude = null): ?Vehicle
    {
        $qb = $this->createQueryBuilder('v')
            ->where('v.company = :company')->setParameter('company', $company)
            ->andWhere('UPPER(v.plate) = :plate')->setParameter('plate', mb_strtoupper($plate))
            ->setMaxResults(1);
        if ($exclude !== null && $exclude->getId() !== null) {
            $qb->andWhere('v.id != :self')->setParameter('self', $exclude->getId());
        }

        return $qb->getQuery()->getOneOrNullResult();
    }
}
