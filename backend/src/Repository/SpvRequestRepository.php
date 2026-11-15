<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\SpvDocument;
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

    /** The most recent request of a type that was not rejected by ANAF (pending, requested or answered). */
    public function findLatestOfType(Company $company, string $type): ?SpvRequest
    {
        return $this->createQueryBuilder('r')
            ->where('r.company = :company')
            ->andWhere('r.requestType = :type')
            ->andWhere('r.status != :error')
            ->setParameter('company', $company)
            ->setParameter('type', $type)
            ->setParameter('error', SpvRequest::STATUS_ERROR)
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByAnswerDocument(SpvDocument $document): ?SpvRequest
    {
        return $this->findOneBy(['answerDocument' => $document]);
    }
}
