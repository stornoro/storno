<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\EFacturaMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EFacturaMessage>
 */
class EFacturaMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EFacturaMessage::class);
    }

    public function findByCompanyPaginated(Company $company, array $filters = [], int $page = 1, int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('m')
            ->leftJoin('m.invoice', 'i')->addSelect('i')
            ->where('m.company = :company')
            ->setParameter('company', $company)
            ->orderBy('m.createdAt', 'DESC');

        if (isset($filters['messageType'])) {
            $qb->andWhere('m.messageType = :messageType')
                ->setParameter('messageType', $filters['messageType']);
        }

        if (isset($filters['status'])) {
            $qb->andWhere('m.status = :status')
                ->setParameter('status', $filters['status']);
        }

        if (isset($filters['search'])) {
            $qb->andWhere('m.anafMessageId LIKE :search OR m.details LIKE :search')
                ->setParameter('search', '%' . $filters['search'] . '%');
        }

        $qb->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        $paginator = new Paginator($qb);

        return [
            'data' => iterator_to_array($paginator),
            'total' => count($paginator),
            'page' => $page,
            'limit' => $limit,
        ];
    }

    public function findByAnafMessageId(string $anafMessageId): ?EFacturaMessage
    {
        return $this->createQueryBuilder('m')
            ->where('m.anafMessageId = :id')
            ->setParameter('id', $anafMessageId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
