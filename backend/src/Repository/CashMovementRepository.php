<?php

namespace App\Repository;

use App\Entity\CashMovement;
use App\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CashMovement>
 */
class CashMovementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CashMovement::class);
    }

    /**
     * @return CashMovement[]
     */
    public function findInRange(Company $company, string $currency, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.company = :company')
            ->andWhere('m.currency = :currency')
            ->andWhere('m.movementDate >= :from')
            ->andWhere('m.movementDate <= :to')
            ->setParameter('company', $company)
            ->setParameter('currency', $currency)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('m.movementDate', 'ASC')
            ->addOrderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function sumNetInRange(Company $company, string $currency, \DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        $row = $this->createQueryBuilder('m')
            ->select(
                "COALESCE(SUM(CASE WHEN m.direction = 'in' THEN m.amount ELSE 0 END), 0) AS in_total",
                "COALESCE(SUM(CASE WHEN m.direction = 'out' THEN m.amount ELSE 0 END), 0) AS out_total",
            )
            ->where('m.company = :company')
            ->andWhere('m.currency = :currency')
            ->andWhere('m.movementDate >= :from')
            ->andWhere('m.movementDate <= :to')
            ->setParameter('company', $company)
            ->setParameter('currency', $currency)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleResult();

        return (float) $row['in_total'] - (float) $row['out_total'];
    }
}
