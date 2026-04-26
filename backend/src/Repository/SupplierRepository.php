<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\Supplier;
use App\Service\ExchangeRateService;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Supplier>
 */
class SupplierRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly ExchangeRateService $exchangeRateService,
    ) {
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
     * @return array{data: array<array<string, mixed>>, total: int, hasForeignCurrencies: bool}
     */
    /** Allowed sort keys → SQL ORDER BY fragments. */
    private const SORT_MAP = [
        'recent'         => 's.created_at DESC',
        'mostInvoiced'   => 'invoiceTotal DESC, s.created_at DESC',
        'mostInvoices'   => 'invoiceCount DESC, s.created_at DESC',
        'recentActivity' => 'lastInvoiceDate DESC, s.created_at DESC',
        'name'           => 's.name ASC',
    ];

    /**
     * @param array{search?: ?string, sort?: ?string, vatPayer?: ?string, hasInvoices?: ?string, source?: ?string} $filters
     */
    public function findByCompanyGrouped(Company $company, int $page = 1, int $limit = 20, ?string $search = null, array $filters = []): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $companyId = $company->getId()->toRfc4122();
        $defaultCurrency = $company->getDefaultCurrency() ?? 'RON';
        $defaultRate = $this->exchangeRateService->getRate($defaultCurrency) ?? 1.0;
        $fallbackRateSql = $this->exchangeRateService->buildFallbackRateSql($conn, $companyId, $defaultCurrency);

        // Defaults bound as named placeholders so user-controlled values can never
        // become part of the SQL fragment. Matches DashboardController / SalesAnalysisService.
        $convertTotalSql = "CASE WHEN currency = :defaultCurrency THEN total ELSE total * COALESCE(exchange_rate, $fallbackRateSql) / :defaultRate END";

        $clauses = [];
        $extraParams = [];
        if ($search) {
            $clauses[] = '(s.name LIKE :search OR s.cif LIKE :search OR s.vat_code LIKE :search)';
            $extraParams['search'] = "%$search%";
        }
        if (!empty($filters['vatPayer']) && in_array($filters['vatPayer'], ['yes', 'no'], true)) {
            $clauses[] = 's.is_vat_payer = :vatPayer';
            $extraParams['vatPayer'] = $filters['vatPayer'] === 'yes' ? 1 : 0;
        }
        if (!empty($filters['source']) && in_array($filters['source'], ['anaf_sync', 'manual'], true)) {
            $clauses[] = 's.source = :source';
            $extraParams['source'] = $filters['source'];
        }
        $hasInvoicesClause = '';
        if (!empty($filters['hasInvoices']) && in_array($filters['hasInvoices'], ['active', 'dormant'], true)) {
            $hasInvoicesClause = $filters['hasInvoices'] === 'active'
                ? ' AND COALESCE(inv.invoice_count, 0) > 0'
                : ' AND COALESCE(inv.invoice_count, 0) = 0';
        }
        $whereExtra = $clauses ? ' AND ' . implode(' AND ', $clauses) : '';

        $sortKey = is_string($filters['sort'] ?? null) ? $filters['sort'] : 'recent';
        $orderBy = self::SORT_MAP[$sortKey] ?? self::SORT_MAP['recent'];

        $hasForeignCurrencies = (bool) $conn->fetchOne(
            'SELECT 1 FROM invoice WHERE company_id = :companyId AND deleted_at IS NULL AND direction = :incoming AND currency != :defaultCurrency LIMIT 1',
            ['companyId' => $companyId, 'incoming' => 'incoming', 'defaultCurrency' => $defaultCurrency],
        );

        $sql = "
            SELECT
                s.id, s.name, s.cif, s.vat_code AS vatCode, s.is_vat_payer AS isVatPayer,
                s.address, s.city, s.email, s.last_synced_at AS lastSyncedAt, s.source,
                s.created_at AS createdAt,
                COALESCE(inv.invoice_count, 0) AS invoiceCount,
                COALESCE(inv.invoice_total, 0) AS invoiceTotal,
                inv.last_invoice_date AS lastInvoiceDate
            FROM supplier s
            LEFT JOIN (
                SELECT sender_cif AS cif,
                       COUNT(*) AS invoice_count,
                       SUM($convertTotalSql) AS invoice_total,
                       MAX(issue_date) AS last_invoice_date
                FROM invoice
                WHERE company_id = :companyId AND deleted_at IS NULL AND direction = 'incoming'
                GROUP BY sender_cif
            ) inv ON inv.cif = s.cif
            WHERE s.company_id = :companyId AND s.deleted_at IS NULL
            $whereExtra
            $hasInvoicesClause
            ORDER BY $orderBy
            LIMIT :pageLimit OFFSET :pageOffset
        ";

        $offset = ($page - 1) * $limit;
        $params = array_merge([
            'companyId'       => $companyId,
            'defaultCurrency' => $defaultCurrency,
            'defaultRate'     => $defaultRate,
            'pageLimit'       => $limit,
            'pageOffset'      => $offset,
        ], $extraParams);
        $types = [
            'pageLimit'  => ParameterType::INTEGER,
            'pageOffset' => ParameterType::INTEGER,
        ];

        $rows = $conn->fetchAllAssociative($sql, $params, $types);

        foreach ($rows as &$row) {
            $row['isVatPayer'] = (bool) $row['isVatPayer'];
            $row['invoiceCount'] = (int) $row['invoiceCount'];
            $row['invoiceTotal'] = round((float) $row['invoiceTotal'], 2);
        }

        // Total has to mirror the same WHERE chain as the page query, so that
        // pagination shows the correct count after filters are applied. We
        // re-run the JOIN so the hasInvoices filter remains accurate.
        $countSql = "
            SELECT COUNT(*)
            FROM supplier s
            LEFT JOIN (
                SELECT sender_cif AS cif, COUNT(*) AS invoice_count
                FROM invoice
                WHERE company_id = :companyId AND deleted_at IS NULL AND direction = 'incoming'
                GROUP BY sender_cif
            ) inv ON inv.cif = s.cif
            WHERE s.company_id = :companyId AND s.deleted_at IS NULL
            $whereExtra
            $hasInvoicesClause
        ";

        $countParams = array_merge(['companyId' => $companyId], $extraParams);
        $total = (int) $conn->fetchOne($countSql, $countParams);

        return ['data' => $rows, 'total' => $total, 'hasForeignCurrencies' => $hasForeignCurrencies];
    }
}
