<?php

namespace App\Repository;

use App\Entity\Client;
use App\Entity\Company;
use App\Service\ExchangeRateService;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Client>
 */
class ClientRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly ExchangeRateService $exchangeRateService,
    ) {
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
            $qb->andWhere('c.name LIKE :search OR c.cui LIKE :search OR c.cnp LIKE :search OR c.email LIKE :search OR c.idNumber LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        $qb->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        return new Paginator($qb);
    }

    /**
     * @return array{data: array<array<string, mixed>>, total: int, hasForeignCurrencies: bool, distinctCountries: string[]}
     */
    private const CLIENT_SORT_MAP = [
        'recent'         => 'dedup.group_created_at DESC',
        'mostInvoiced'   => 'invoiceTotal DESC, dedup.group_created_at DESC',
        'mostInvoices'   => 'invoiceCount DESC, dedup.group_created_at DESC',
        'recentActivity' => 'lastInvoiceDate DESC, dedup.group_created_at DESC',
        'name'           => 'c.name ASC',
    ];

    /**
     * @param array{search?: ?string, country?: ?string, sort?: ?string, vatPayer?: ?string, hasInvoices?: ?string, source?: ?string} $filters
     */
    public function findByCompanyGrouped(Company $company, int $page = 1, int $limit = 20, ?string $search = null, ?string $country = null, array $filters = []): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $companyId = $company->getId()->toRfc4122();
        $defaultCurrency = $company->getDefaultCurrency() ?? 'RON';
        $defaultRate = $this->exchangeRateService->getRate($defaultCurrency) ?? 1.0;
        $fallbackRateSql = $this->exchangeRateService->buildFallbackRateSql($conn, $companyId, $defaultCurrency);

        $convertTotalSql = "CASE WHEN currency = '$defaultCurrency' THEN total ELSE total * COALESCE(exchange_rate, $fallbackRateSql) / $defaultRate END";

        $innerClauses = [];
        $innerParams = [];
        if ($search) {
            $innerClauses[] = '(c.name LIKE ? OR c.cui LIKE ? OR c.cnp LIKE ? OR c.email LIKE ? OR c.id_number LIKE ? OR c.vat_code LIKE ?)';
            $innerParams = array_merge($innerParams, array_fill(0, 6, "%$search%"));
        }
        if ($country) {
            $innerClauses[] = 'c.country = ?';
            $innerParams[] = $country;
        }
        if (!empty($filters['vatPayer']) && in_array($filters['vatPayer'], ['yes', 'no'], true)) {
            $innerClauses[] = 'c.is_vat_payer = ?';
            $innerParams[] = $filters['vatPayer'] === 'yes' ? 1 : 0;
        }
        if (!empty($filters['source']) && in_array($filters['source'], ['anaf_sync', 'manual'], true)) {
            $innerClauses[] = 'c.source = ?';
            $innerParams[] = $filters['source'];
        }
        $innerWhere = $innerClauses ? ' AND ' . implode(' AND ', $innerClauses) : '';

        $hasInvoicesClause = '';
        if (!empty($filters['hasInvoices']) && in_array($filters['hasInvoices'], ['active', 'dormant'], true)) {
            $hasInvoicesClause = $filters['hasInvoices'] === 'active'
                ? ' WHERE COALESCE(s.invoice_count, 0) > 0'
                : ' WHERE COALESCE(s.invoice_count, 0) = 0';
        }

        $sortKey = is_string($filters['sort'] ?? null) ? $filters['sort'] : 'recent';
        $orderBy = self::CLIENT_SORT_MAP[$sortKey] ?? self::CLIENT_SORT_MAP['recent'];

        $hasForeignCurrencies = (bool) $conn->fetchOne(
            "SELECT 1 FROM invoice WHERE company_id = ? AND deleted_at IS NULL AND direction = 'outgoing' AND currency != ? LIMIT 1",
            [$companyId, $defaultCurrency],
        );

        $distinctCountriesRows = $conn->fetchFirstColumn(
            "SELECT DISTINCT c.country FROM client c WHERE c.company_id = ? AND c.deleted_at IS NULL AND c.country IS NOT NULL AND c.country != '' ORDER BY c.country ASC",
            [$companyId],
        );

        // Pagination must happen AFTER JOINing with invoice stats so we can sort
        // by metrics and apply the hasInvoices filter. The dedup CTE collapses
        // duplicate clients to a single representative per (cui|cnp) group.
        $sql = "
            SELECT
                c.id, c.type, c.name, c.cui, c.cnp, c.vat_code AS vatCode, c.is_vat_payer AS isVatPayer,
                c.address, c.city, c.email, c.country, c.vies_valid AS viesValid, c.source,
                COALESCE(s.invoice_count, 0) AS invoiceCount,
                COALESCE(s.invoice_total, 0) AS invoiceTotal,
                s.last_invoice_date AS lastInvoiceDate,
                dedup.group_created_at AS groupCreatedAt
            FROM (
                SELECT MIN(c.id) AS id, MAX(c.created_at) AS group_created_at
                FROM client c
                WHERE c.company_id = ? AND c.deleted_at IS NULL
                $innerWhere
                GROUP BY COALESCE(c.cui, c.cnp, CAST(c.id AS CHAR))
            ) dedup
            INNER JOIN client c ON c.id = dedup.id
            LEFT JOIN (
                SELECT receiver_cif AS cif,
                       COUNT(*) AS invoice_count,
                       SUM($convertTotalSql) AS invoice_total,
                       MAX(issue_date) AS last_invoice_date
                FROM invoice
                WHERE company_id = ? AND deleted_at IS NULL AND direction = 'outgoing'
                GROUP BY receiver_cif
            ) s ON s.cif = COALESCE(c.cui, c.cnp)
            $hasInvoicesClause
            ORDER BY $orderBy
            LIMIT ? OFFSET ?
        ";

        $offset = ($page - 1) * $limit;
        $params = array_merge([$companyId], $innerParams, [$companyId, $limit, $offset]);
        $types = array_merge(
            array_fill(0, 1 + count($innerParams), ParameterType::STRING),
            [ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER],
        );

        $rows = $conn->fetchAllAssociative($sql, $params, $types);

        foreach ($rows as &$row) {
            $row['isVatPayer'] = (bool) $row['isVatPayer'];
            $row['viesValid'] = $row['viesValid'] === null ? null : (bool) $row['viesValid'];
            $row['invoiceCount'] = (int) $row['invoiceCount'];
            $row['invoiceTotal'] = round((float) $row['invoiceTotal'], 2);
        }

        $countSql = "
            SELECT COUNT(*) FROM (
                SELECT dedup.id, COALESCE(s.invoice_count, 0) AS invoice_count
                FROM (
                    SELECT MIN(c.id) AS id
                    FROM client c
                    WHERE c.company_id = ? AND c.deleted_at IS NULL
                    $innerWhere
                    GROUP BY COALESCE(c.cui, c.cnp, CAST(c.id AS CHAR))
                ) dedup
                INNER JOIN client c ON c.id = dedup.id
                LEFT JOIN (
                    SELECT receiver_cif AS cif, COUNT(*) AS invoice_count
                    FROM invoice
                    WHERE company_id = ? AND deleted_at IS NULL AND direction = 'outgoing'
                    GROUP BY receiver_cif
                ) s ON s.cif = COALESCE(c.cui, c.cnp)
                $hasInvoicesClause
            ) grouped
        ";

        $countParams = array_merge([$companyId], $innerParams, [$companyId]);
        $total = (int) $conn->fetchOne($countSql, $countParams);

        return ['data' => $rows, 'total' => $total, 'hasForeignCurrencies' => $hasForeignCurrencies, 'distinctCountries' => $distinctCountriesRows];
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
