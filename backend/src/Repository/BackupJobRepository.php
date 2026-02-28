<?php

namespace App\Repository;

use App\Entity\BackupJob;
use App\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BackupJob>
 *
 * @method BackupJob|null find($id, $lockMode = null, $lockVersion = null)
 * @method BackupJob|null findOneBy(array $criteria, array $orderBy = null)
 * @method BackupJob[]    findAll()
 * @method BackupJob[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BackupJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BackupJob::class);
    }

    /**
     * @return BackupJob[]
     */
    public function findByCompany(Company $company, int $limit = 20): array
    {
        return $this->createQueryBuilder('bj')
            ->andWhere('bj.company = :company')
            ->setParameter('company', $company)
            ->orderBy('bj.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function hasActiveJob(Company $company): bool
    {
        $count = $this->createQueryBuilder('bj')
            ->select('COUNT(bj.id)')
            ->andWhere('bj.company = :company')
            ->andWhere('bj.status IN (:statuses)')
            ->setParameter('company', $company)
            ->setParameter('statuses', ['pending', 'processing'])
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}
