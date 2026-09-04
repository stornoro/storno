<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\SpvRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SpvRequest> */
class SpvRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpvRequest::class);
    }

    /** @return array{items: list<SpvRequest>, total: int} */
    public function paginate(Company $company, int $page, int $limit, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('r')
            ->andWhere('r.company = :company')->setParameter('company', $company)
            ->orderBy('r.createdAt', 'DESC');
        if ($status !== null && $status !== '') {
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        }
        $total = (int) (clone $qb)->select('COUNT(r.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }

    public function findOneByAnafRequestId(Company $company, string $anafRequestId): ?SpvRequest
    {
        return $this->findOneBy(['company' => $company, 'anafRequestId' => $anafRequestId]);
    }
}
