<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\Supplier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Supplier>
 */
class SupplierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Supplier::class);
    }

    /**
     * @return Supplier[]
     */
    public function findAllByCompany(Company $company): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.company = :company')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByCif(Company $company, string $cif): ?Supplier
    {
        return $this->createQueryBuilder('s')
            ->where('s.company = :company')
            ->andWhere('s.cif = :cif')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->setParameter('cif', $cif)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByCompanyPaginated(Company $company, array $filters = [], int $page = 1, int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('s')
            ->where('s.company = :company')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->orderBy('s.createdAt', 'DESC');

        if (isset($filters['search'])) {
            $qb->andWhere('s.name LIKE :search OR s.cif LIKE :search')
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

    /**
     * @return array{data: array<array<string, mixed>>, total: int}
     */
    public function findByCompanyGrouped(Company $company, int $page = 1, int $limit = 20, ?string $search = null): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $companyId = $company->getId()->toRfc4122();

        $searchClause = '';
        $searchParams = [];
        if ($search) {
            $searchClause = 'AND (s.name LIKE ? OR s.cif LIKE ?)';
            $searchParams = ["%$search%", "%$search%"];
        }

        $sql = "
            SELECT
                s.id, s.name, s.cif, s.vat_code AS vatCode, s.is_vat_payer AS isVatPayer,
                s.address, s.city, s.email, s.last_synced_at AS lastSyncedAt,
                COALESCE(inv.invoice_count, 0) AS invoiceCount,
                COALESCE(inv.invoice_total, 0) AS invoiceTotal
            FROM supplier s
            LEFT JOIN (
                SELECT sender_cif AS cif, COUNT(*) AS invoice_count, SUM(total) AS invoice_total
                FROM invoice
                WHERE company_id = ? AND deleted_at IS NULL AND direction = 'incoming'
                GROUP BY sender_cif
            ) inv ON inv.cif = s.cif
            WHERE s.company_id = ? AND s.deleted_at IS NULL
            $searchClause
            ORDER BY s.created_at DESC
            LIMIT ? OFFSET ?
        ";

        $offset = ($page - 1) * $limit;
        $params = array_merge([$companyId, $companyId], $searchParams, [$limit, $offset]);
        $types = array_merge(
            array_fill(0, 2 + count($searchParams), ParameterType::STRING),
            [ParameterType::INTEGER, ParameterType::INTEGER],
        );

        $rows = $conn->fetchAllAssociative($sql, $params, $types);

        foreach ($rows as &$row) {
            $row['isVatPayer'] = (bool) $row['isVatPayer'];
            $row['invoiceCount'] = (int) $row['invoiceCount'];
            $row['invoiceTotal'] = round((float) $row['invoiceTotal'], 2);
        }

        $countSql = "
            SELECT COUNT(*)
            FROM supplier s
            WHERE s.company_id = ? AND s.deleted_at IS NULL
            $searchClause
        ";

        $countParams = array_merge([$companyId], $searchParams);
        $total = (int) $conn->fetchOne($countSql, $countParams);

        return ['data' => $rows, 'total' => $total];
    }
}
