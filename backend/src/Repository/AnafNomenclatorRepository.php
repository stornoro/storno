<?php

namespace App\Repository;

use App\Entity\AnafNomenclatorEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AnafNomenclatorEntry> */
class AnafNomenclatorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnafNomenclatorEntry::class);
    }

    /** @return list<AnafNomenclatorEntry> */
    public function children(string $kind, string $parentKey, ?string $query = null, int $limit = 500): array
    {
        $qb = $this->createQueryBuilder('n')
            ->andWhere('n.kind = :kind')->setParameter('kind', $kind)
            ->andWhere('n.parentKey = :parent')->setParameter('parent', $parentKey)
            ->orderBy('n.nameNormalized', 'ASC')
            ->setMaxResults($limit);
        $q = $query !== null ? AnafNomenclatorEntry::normalize($query) : '';
        if ($q !== '') {
            // prefix on any word of the name: "maniu" finds "bld. iuliu maniu"
            $qb->andWhere('n.nameNormalized LIKE :prefix OR n.nameNormalized LIKE :word')
                ->setParameter('prefix', $q . '%')
                ->setParameter('word', '% ' . $q . '%');
        }

        return $qb->getQuery()->getResult();
    }

    public function countChildren(string $kind, string $parentKey): int
    {
        return (int) $this->createQueryBuilder('n')->select('COUNT(n.id)')
            ->andWhere('n.kind = :kind')->setParameter('kind', $kind)
            ->andWhere('n.parentKey = :parent')->setParameter('parent', $parentKey)
            ->getQuery()->getSingleScalarResult();
    }

    public function findOneByCode(string $kind, string $parentKey, string $code): ?AnafNomenclatorEntry
    {
        return $this->findOneBy(['kind' => $kind, 'parentKey' => $parentKey, 'code' => $code]);
    }

    /** @return list<string> distinct parent keys already cached for a kind (e.g. localities whose streets are stored) */
    public function cachedParents(string $kind): array
    {
        $rows = $this->createQueryBuilder('n')->select('DISTINCT n.parentKey AS p')
            ->andWhere('n.kind = :kind')->setParameter('kind', $kind)
            ->getQuery()->getScalarResult();

        return array_values(array_map(static fn (array $r) => (string) $r['p'], $rows));
    }
}
