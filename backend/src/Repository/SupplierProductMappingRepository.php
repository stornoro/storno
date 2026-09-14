<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Supplier;
use App\Entity\SupplierProductMapping;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SupplierProductMapping>
 */
class SupplierProductMappingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SupplierProductMapping::class);
    }

    /** Most recently used mapping for one key of one supplier; confirmed (hand-picked) mappings first. */
    public function findOneByKey(Supplier $supplier, string $field, string $value): ?SupplierProductMapping
    {
        if (!in_array($field, ['barcode', 'supplierCode', 'descriptionKey'], true)) {
            throw new \InvalidArgumentException("Unknown mapping key $field");
        }

        return $this->createQueryBuilder('m')
            ->andWhere('m.supplier = :supplier')
            ->andWhere("m.$field = :value")
            ->setParameter('supplier', $supplier)
            ->setParameter('value', $value)
            ->orderBy('m.confirmed', 'DESC')
            ->addOrderBy('m.lastUsedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return SupplierProductMapping[] */
    public function findBySupplier(Supplier $supplier): array
    {
        return $this->findBy(['supplier' => $supplier], ['lastUsedAt' => 'DESC']);
    }
}
