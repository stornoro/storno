<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\ImportJob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImportJob>
 *
 * @method ImportJob|null find($id, $lockMode = null, $lockVersion = null)
 * @method ImportJob|null findOneBy(array $criteria, array $orderBy = null)
 * @method ImportJob[]    findAll()
 * @method ImportJob[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ImportJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportJob::class);
    }

    /**
     * @return ImportJob[]
     */
    public function findByCompany(Company $company, int $limit = 20): array
    {
        return $this->createQueryBuilder('ij')
            ->andWhere('ij.company = :company')
            ->setParameter('company', $company)
            ->orderBy('ij.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
