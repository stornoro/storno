<?php

namespace App\Repository;

use App\Entity\Client;
use App\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Client>
 */
class ClientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Client::class);
    }

    /**
     * @return Paginator<Client>
     */
    public function findByCompanyPaginated(Company $company, int $page = 1, int $limit = 20, ?string $search = null): Paginator
    {
        $qb = $this->createQueryBuilder('c')
            ->addSelect('CASE WHEN c.cui IS NOT NULL THEN c.cui ELSE c.cnp END AS HIDDEN identifier')
            ->where('c.company = :company')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->orderBy('c.createdAt', 'DESC');

        if ($search) {
            $qb->andWhere('c.name LIKE :search OR c.cui LIKE :search OR c.cnp LIKE :search OR c.email LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        $qb->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        return new Paginator($qb);
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
            $searchClause = 'AND (c.name LIKE ? OR c.cui LIKE ? OR c.cnp LIKE ? OR c.email LIKE ?)';
            $searchParams = ["%$search%", "%$search%", "%$search%", "%$search%"];
        }

        // Main query: group clients by identifier, join outgoing invoice stats only
        $sql = "
            SELECT
                c.id, c.type, c.name, c.cui, c.cnp, c.vat_code AS vatCode, c.is_vat_payer AS isVatPayer,
                c.address, c.city, c.email, c.country, c.vies_valid AS viesValid,
                COALESCE(s.invoice_count, 0) AS invoiceCount,
                COALESCE(s.invoice_total, 0) AS invoiceTotal
            FROM client c
            LEFT JOIN (
                SELECT receiver_cif AS cif, COUNT(*) AS invoice_count, SUM(total) AS invoice_total
                FROM invoice
                WHERE company_id = ? AND deleted_at IS NULL AND direction = 'outgoing'
                GROUP BY receiver_cif
            ) s ON s.cif = COALESCE(c.cui, c.cnp)
            WHERE c.company_id = ? AND c.deleted_at IS NULL
            $searchClause
            GROUP BY COALESCE(c.cui, c.cnp, CAST(c.id AS CHAR))
            ORDER BY c.created_at DESC
            LIMIT ? OFFSET ?
        ";

        $offset = ($page - 1) * $limit;
        $params = array_merge([$companyId, $companyId], $searchParams, [$limit, $offset]);
        $types = array_merge(
            array_fill(0, 2 + count($searchParams), ParameterType::STRING),
            [ParameterType::INTEGER, ParameterType::INTEGER],
        );

        $rows = $conn->fetchAllAssociative($sql, $params, $types);

        // Convert types
        foreach ($rows as &$row) {
            $row['isVatPayer'] = (bool) $row['isVatPayer'];
            $row['viesValid'] = $row['viesValid'] === null ? null : (bool) $row['viesValid'];
            $row['invoiceCount'] = (int) $row['invoiceCount'];
            $row['invoiceTotal'] = round((float) $row['invoiceTotal'], 2);
        }

        // Count query for pagination
        $countSql = "
            SELECT COUNT(*) FROM (
                SELECT 1
                FROM client c
                WHERE c.company_id = ? AND c.deleted_at IS NULL
                $searchClause
                GROUP BY COALESCE(c.cui, c.cnp, CAST(c.id AS CHAR))
            ) grouped
        ";

        $countParams = array_merge([$companyId], $searchParams);
        $total = (int) $conn->fetchOne($countSql, $countParams);

        return ['data' => $rows, 'total' => $total];
    }

    /**
     * @return Client[]
     */
    public function findAllByCompany(Company $company): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.company = :company')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByCui(Company $company, string $cui): ?Client
    {
        return $this->createQueryBuilder('c')
            ->where('c.company = :company')
            ->andWhere('c.cui = :cui')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->setParameter('cui', $cui)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find client by CUI including soft-deleted (for sync restore).
     */
    public function findByCuiIncludingDeleted(Company $company, string $cui): ?Client
    {
        return $this->createQueryBuilder('c')
            ->where('c.company = :company')
            ->andWhere('c.cui = :cui')
            ->setParameter('company', $company)
            ->setParameter('cui', $cui)
            ->orderBy('c.deletedAt', 'ASC')  // active first, then soft-deleted
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find an individual client by CNP (for physical persons with a real CNP).
     */
    public function findByCnp(Company $company, string $cnp): ?Client
    {
        return $this->createQueryBuilder('c')
            ->where('c.company = :company')
            ->andWhere('c.cnp = :cnp')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->setParameter('cnp', $cnp)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find an individual client by name (for physical persons without a real CUI).
     */
    public function findIndividualByName(Company $company, string $name): ?Client
    {
        return $this->createQueryBuilder('c')
            ->where('c.company = :company')
            ->andWhere('c.type = :type')
            ->andWhere('c.name = :name')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->setParameter('type', 'individual')
            ->setParameter('name', $name)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find a client by CUI across all companies in the organization.
     */
    public function findByCuiInOrganization(string $cui, array $companyIds): ?Client
    {
        return $this->createQueryBuilder('c')
            ->where('c.cui = :cui')
            ->andWhere('c.company IN (:companies)')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('cui', $cui)
            ->setParameter('companies', $companyIds)
            ->orderBy('c.updatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
