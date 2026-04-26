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

    private const PRODUCT_SORT_MAP = [
        'recent'    => ['p.createdAt', 'DESC'],
        'name'      => ['p.name', 'ASC'],
        'priceHigh' => ['p.defaultPrice', 'DESC'],
        'priceLow'  => ['p.defaultPrice', 'ASC'],
    ];

    /**
     * @param array{sort?: ?string, type?: ?string, status?: ?string, usage?: ?string, categoryId?: ?string, source?: ?string} $filters
     * @return Paginator<Product>
     */
    public function findByCompanyPaginated(Company $company, int $page = 1, int $limit = 20, ?string $search = null, array $filters = []): Paginator
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.company = :company')
            ->setParameter('company', $company);

        if ($search) {
            $qb->andWhere('p.name LIKE :search OR p.code LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        if (!empty($filters['type']) && in_array($filters['type'], ['product', 'service'], true)) {
            $qb->andWhere('p.isService = :isService')
                ->setParameter('isService', $filters['type'] === 'service');
        }

        if (!empty($filters['status']) && in_array($filters['status'], ['active', 'inactive'], true)) {
            $qb->andWhere('p.isActive = :isActive')
                ->setParameter('isActive', $filters['status'] === 'active');
        }

        if (!empty($filters['usage']) && in_array($filters['usage'], ['sales', 'purchases', 'both', 'internal'], true)) {
            $qb->andWhere('p.usage = :usage')
                ->setParameter('usage', $filters['usage']);
        }

        if (!empty($filters['source']) && in_array($filters['source'], ['anaf_sync', 'manual'], true)) {
            $qb->andWhere('p.source = :source')
                ->setParameter('source', $filters['source']);
        }

        if (!empty($filters['categoryId'])) {
            if ($filters['categoryId'] === 'none') {
                $qb->andWhere('p.category IS NULL');
            } else {
                $qb->andWhere('p.category = :categoryId')
                    ->setParameter('categoryId', $filters['categoryId']);
            }
        }

        $sortKey = is_string($filters['sort'] ?? null) ? $filters['sort'] : 'recent';
        [$sortField, $sortDir] = self::PRODUCT_SORT_MAP[$sortKey] ?? self::PRODUCT_SORT_MAP['recent'];
        $qb->orderBy($sortField, $sortDir);

        $qb->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        return new Paginator($qb);
    }
}
