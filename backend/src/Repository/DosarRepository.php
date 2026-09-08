<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\Dosar;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Dosar> */
class DosarRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Dosar::class);
    }

    /** @return list<Dosar> */
    public function findForCompany(Company $company, ?string $type = null, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('d')
            ->andWhere('d.company = :company')->setParameter('company', $company)
            ->orderBy('d.status', 'ASC')
            ->addOrderBy('d.deadlineAt', 'ASC')
            ->addOrderBy('d.updatedAt', 'DESC');
        if ($type !== null && $type !== '') {
            $qb->andWhere('d.type = :type')->setParameter('type', $type);
        }
        if ($status !== null && $status !== '') {
            $qb->andWhere('d.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /** Open dosare whose deadline falls within the next $days days (or already passed), for reminders. */
    /** @return list<Dosar> */
    public function findWithUpcomingDeadline(int $days): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.status != :closed')->setParameter('closed', Dosar::STATUS_CLOSED)
            ->andWhere('d.deadlineAt IS NOT NULL')
            ->andWhere('d.deadlineAt <= :until')->setParameter('until', (new \DateTimeImmutable('today'))->modify(sprintf('+%d days', $days)))
            ->orderBy('d.deadlineAt', 'ASC')
            ->getQuery()->getResult();
    }

    public function findOneAnnualReturn(Company $company, int $year): ?Dosar
    {
        foreach ($this->findBy(['company' => $company, 'type' => Dosar::TYPE_ANNUAL_RETURN]) as $d) {
            if ((int) ($d->getSubject()['an'] ?? 0) === $year) {
                return $d;
            }
        }

        return null;
    }
}
