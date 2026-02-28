<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /**
     * @return Product[]
     */
    public function findAllByCompany(Company $company): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.company = :company')
            ->setParameter('company', $company)
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Paginator<Product>
     */
    public function findByCompanyPaginated(Company $company, int $page = 1, int $limit = 20, ?string $search = null): Paginator
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.company = :company')
            ->setParameter('company', $company)
            ->orderBy('p.createdAt', 'DESC');

        if ($search) {
            $qb->andWhere('p.name LIKE :search OR p.code LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        $qb->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        return new Paginator($qb);
    }
}
