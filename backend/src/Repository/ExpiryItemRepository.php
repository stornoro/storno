<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\ExpiryItem;
use App\Entity\Vehicle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ExpiryItem> */
class ExpiryItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExpiryItem::class);
    }

    /**
     * Items of a company, soonest first (expired ones come first because they have the
     * smallest dates); closed (renewed) items only when asked.
     *
     * @return list<ExpiryItem>
     */
    public function findForCompany(Company $company, ?string $kind = null, ?Vehicle $vehicle = null, bool $includeClosed = false, bool $companyLevelOnly = false): array
    {
        $qb = $this->createQueryBuilder('e')
            ->andWhere('e.company = :company')->setParameter('company', $company)
            ->orderBy('e.expiresAt', 'ASC')
            ->addOrderBy('e.label', 'ASC');
        if ($kind !== null && $kind !== '') {
            $qb->andWhere('e.kind = :kind')->setParameter('kind', $kind);
        }
        if ($vehicle !== null) {
            $qb->andWhere('e.vehicle = :vehicle')->setParameter('vehicle', $vehicle);
        }
        if ($companyLevelOnly) {
            $qb->andWhere('e.vehicle IS NULL');
        }
        if (!$includeClosed) {
            $qb->andWhere('e.closedAt IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Open items of a company expiring within $days days of $today, plus the ones already expired.
     *
     * @return list<ExpiryItem>
     */
    public function findUpcoming(Company $company, int $days, ?\DateTimeImmutable $today = null): array
    {
        $today = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0);

        return $this->createQueryBuilder('e')
            ->andWhere('e.company = :company')->setParameter('company', $company)
            ->andWhere('e.closedAt IS NULL')
            ->andWhere('e.expiresAt <= :until')->setParameter('until', $today->modify(sprintf('+%d days', $days)))
            ->orderBy('e.expiresAt', 'ASC')
            ->addOrderBy('e.label', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Open items across all companies expiring within $days days of $today (for the daily reminder).
     *
     * @return list<ExpiryItem>
     */
    public function findOpenExpiringWithin(int $days, \DateTimeImmutable $today): array
    {
        $today = $today->setTime(0, 0);

        return $this->createQueryBuilder('e')
            ->andWhere('e.closedAt IS NULL')
            ->andWhere('e.expiresAt >= :today')->setParameter('today', $today)
            ->andWhere('e.expiresAt <= :until')->setParameter('until', $today->modify(sprintf('+%d days', $days)))
            ->orderBy('e.expiresAt', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return array<string, ExpiryItem> the soonest open item per vehicle id */
    public function nextPerVehicle(Company $company): array
    {
        $next = [];
        foreach ($this->findForCompany($company) as $item) {
            $vid = $item->getVehicleId();
            if ($vid === null || isset($next[$vid])) {
                continue;
            }
            $next[$vid] = $item;
        }

        return $next;
    }
}
