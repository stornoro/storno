<?php

namespace App\Repository;

use App\Entity\Client;
use App\Entity\Company;
use App\Service\Cache\CompanyStatsCache;
use App\Service\ExchangeRateService;
use Doctrine\DBAL\ArrayParameterType;
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
        private readonly CompanyStatsCache $statsCache,
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
    /** The trailing id breaks ties on created_at, so consecutive pages never overlap. */
    private const CLIENT_SORT_MAP = [
        'recent'         => 'c.created_at DESC, c.id DESC',
        'mostInvoiced'   => 'invoiceTotal DESC, c.created_at DESC, c.id DESC',
        'mostInvoices'   => 'invoiceCount DESC, c.created_at DESC, c.id DESC',
        'recentActivity' => 'lastInvoiceDate DESC, c.created_at DESC, c.id DESC',
        'name'           => 'c.name ASC, c.id ASC',
    ];

    /**
     * Whole-company aggregates live until the company's next write moves the cache version; the
     * TTL only bounds writes that bypass the ORM.
     */
    private const STATS_TTL = 600;

    /** Sort keys that order by an invoice statistic, so the page can only be cut after aggregating. */
    private const CLIENT_STAT_SORTS = ['mostInvoiced', 'mostInvoices', 'recentActivity'];

    private const CLIENT_COLUMNS = '
        c.id, c.type, c.name, c.cui, c.cnp, c.vat_code AS vatCode, c.is_vat_payer AS isVatPayer,
        c.address, c.city, c.email, c.country, c.vies_valid AS viesValid, c.source,
        c.status, c.credit_limit AS creditLimit, c.affiliated, c.inactive,
        c.vat_registered AS vatRegistered, c.vat_on_collection AS vatOnCollection,
        c.efactura_registered AS efacturaRegistered, c.vat_status_checked_at AS vatStatusCheckedAt';

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

        // Defaults bound as named placeholders. Matches DashboardController / SalesAnalysisService.
        $convertTotalSql = "CASE WHEN currency = :defaultCurrency THEN total ELSE total * COALESCE(exchange_rate, $fallbackRateSql) / :defaultRate END";

        $innerClauses = [];
        $innerParams = [];
        if ($search) {
            $innerClauses[] = '(c.name LIKE :search OR c.cui LIKE :search OR c.cnp LIKE :search OR c.email LIKE :search OR c.id_number LIKE :search OR c.vat_code LIKE :search)';
            $innerParams['search'] = "%$search%";
        }
        if ($country) {
            $innerClauses[] = 'c.country = :country';
            $innerParams['country'] = $country;
        }
        if (!empty($filters['vatPayer']) && in_array($filters['vatPayer'], ['yes', 'no'], true)) {
            $innerClauses[] = 'c.is_vat_payer = :vatPayer';
            $innerParams['vatPayer'] = $filters['vatPayer'] === 'yes' ? 1 : 0;
        }
        if (!empty($filters['source']) && in_array($filters['source'], ['anaf_sync', 'manual'], true)) {
            $innerClauses[] = 'c.source = :source';
            $innerParams['source'] = $filters['source'];
        }
        // "Has invoices" probes invoice.client_id once per client instead of aggregating the totals.
        // A scalar subquery with LIMIT 1 keeps it a per-row index probe: MariaDB rewrites EXISTS
        // into a semi-join that materializes every outgoing invoice of the company first.
        $filtersByInvoices = !empty($filters['hasInvoices']) && in_array($filters['hasInvoices'], ['active', 'dormant'], true);
        $hasInvoices = $filtersByInvoices ? $filters['hasInvoices'] : null;
        if ($filtersByInvoices) {
            $innerClauses[] = "(
                SELECT 1 FROM invoice hi
                WHERE hi.client_id = c.id AND hi.company_id = :companyId AND hi.deleted_at IS NULL AND hi.direction = 'outgoing'
                LIMIT 1
            ) IS " . ($hasInvoices === 'active' ? 'NOT NULL' : 'NULL');
        }
        $innerWhere = $innerClauses ? ' AND ' . implode(' AND ', $innerClauses) : '';

        $sortKey = is_string($filters['sort'] ?? null) ? $filters['sort'] : 'recent';
        $orderBy = self::CLIENT_SORT_MAP[$sortKey] ?? self::CLIENT_SORT_MAP['recent'];
        $sortsByStat = in_array($sortKey, self::CLIENT_STAT_SORTS, true);

        $hasForeignCurrencies = $this->exchangeRateService->hasForeignCurrencies($conn, $companyId, $defaultCurrency, 'outgoing');

        $distinctCountriesRows = $this->statsCache->get($companyId, 'clients.countries', fn () => $conn->fetchFirstColumn(
            'SELECT DISTINCT c.country FROM client c WHERE c.company_id = :companyId AND c.deleted_at IS NULL AND c.country IS NOT NULL AND c.country != \'\' ORDER BY c.country ASC',
            ['companyId' => $companyId],
        ), self::STATS_TTL);

        $offset = ($page - 1) * $limit;
        $pageTypes = [
            'pageLimit'  => ParameterType::INTEGER,
            'pageOffset' => ParameterType::INTEGER,
        ];
        $statParams = ['companyId' => $companyId, 'defaultCurrency' => $defaultCurrency, 'defaultRate' => $defaultRate];

        if ($sortsByStat) {
            // Ordering by a statistic needs it for every client, so this path aggregates the whole
            // company. The result is cached until the company's next write.
            $cacheKey = 'clients.page|' . json_encode([$page, $limit, $innerParams, $hasInvoices, $sortKey, $defaultCurrency, $defaultRate, date('Y-m-d')]);
            $rows = $this->statsCache->get($companyId, $cacheKey, fn () => $conn->fetchAllAssociative(
                'SELECT ' . self::CLIENT_COLUMNS . ",
                    COALESCE(s.invoice_count, 0) AS invoiceCount,
                    COALESCE(s.invoice_total, 0) AS invoiceTotal,
                    s.last_invoice_date AS lastInvoiceDate,
                    c.created_at AS groupCreatedAt
                FROM client c
                LEFT JOIN (
                    SELECT client_id,
                           COUNT(*) AS invoice_count,
                           SUM($convertTotalSql) AS invoice_total,
                           MAX(issue_date) AS last_invoice_date
                    FROM invoice
                    WHERE company_id = :companyId AND deleted_at IS NULL AND direction = 'outgoing' AND client_id IS NOT NULL
                    GROUP BY client_id
                ) s ON s.client_id = c.id
                WHERE c.company_id = :companyId AND c.deleted_at IS NULL
                $innerWhere
                ORDER BY $orderBy
                LIMIT :pageLimit OFFSET :pageOffset",
                array_merge($statParams, ['pageLimit' => $limit, 'pageOffset' => $offset], $innerParams),
                $pageTypes,
            ), self::STATS_TTL);
        } else {
            // Cut the page from the client table alone, then aggregate invoices only for its ids
            // through the invoice.client_id index.
            $clients = $conn->fetchAllAssociative(
                'SELECT ' . self::CLIENT_COLUMNS . ", c.created_at AS groupCreatedAt
                FROM client c
                WHERE c.company_id = :companyId AND c.deleted_at IS NULL
                $innerWhere
                ORDER BY $orderBy
                LIMIT :pageLimit OFFSET :pageOffset",
                array_merge(['companyId' => $companyId, 'pageLimit' => $limit, 'pageOffset' => $offset], $innerParams),
                $pageTypes,
            );

            $stats = [];
            if ($clients) {
                $statRows = $conn->fetchAllAssociative(
                    "SELECT client_id,
                            COUNT(*) AS invoice_count,
                            SUM($convertTotalSql) AS invoice_total,
                            MAX(issue_date) AS last_invoice_date
                     FROM invoice
                     WHERE client_id IN (:clientIds) AND company_id = :companyId AND deleted_at IS NULL AND direction = 'outgoing'
                     GROUP BY client_id",
                    array_merge($statParams, ['clientIds' => array_column($clients, 'id')]),
                    ['clientIds' => ArrayParameterType::STRING],
                );
                foreach ($statRows as $statRow) {
                    $stats[$statRow['client_id']] = $statRow;
                }
            }

            // Same keys, in the same order, as the aggregated query above.
            $rows = [];
            foreach ($clients as $client) {
                $createdAt = $client['groupCreatedAt'];
                unset($client['groupCreatedAt']);
                $stat = $stats[$client['id']] ?? null;
                $client['invoiceCount'] = $stat['invoice_count'] ?? 0;
                $client['invoiceTotal'] = $stat['invoice_total'] ?? 0;
                $client['lastInvoiceDate'] = $stat['last_invoice_date'] ?? null;
                $client['groupCreatedAt'] = $createdAt;
                $rows[] = $client;
            }
        }

        foreach ($rows as &$row) {
            $row['isVatPayer'] = (bool) $row['isVatPayer'];
            $row['viesValid'] = $row['viesValid'] === null ? null : (bool) $row['viesValid'];
            $row['affiliated'] = (bool) $row['affiliated'];
            foreach (['inactive', 'vatRegistered', 'vatOnCollection', 'efacturaRegistered'] as $flag) {
                $row[$flag] = $row[$flag] === null ? null : (bool) $row[$flag];
            }
            $row['invoiceCount'] = (int) $row['invoiceCount'];
            $row['invoiceTotal'] = round((float) $row['invoiceTotal'], 2);
        }
        unset($row);

        $countSql = "SELECT COUNT(*) FROM client c WHERE c.company_id = :companyId AND c.deleted_at IS NULL $innerWhere";
        $countParams = array_merge(['companyId' => $companyId], $innerParams);
        if ($filtersByInvoices) {
            // The filter probes the invoices of every client of the company, so its count is cached.
            $total = (int) $this->statsCache->get($companyId, 'clients.count|' . json_encode([$countParams, $hasInvoices]), fn () => $conn->fetchOne($countSql, $countParams), self::STATS_TTL);
        } else {
            $total = (int) $conn->fetchOne($countSql, $countParams);
        }

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

    /**
     * Lower-cased subset of $emails that belong to a non-deleted client of the company.
     *
     * @param string[] $emails
     *
     * @return string[]
     */
    public function findMatchingClientEmails(Company $company, array $emails): array
    {
        $emails = array_values(array_unique(array_map(static fn (string $e) => mb_strtolower(trim($e)), $emails)));
        if ($emails === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('c')
            ->select('LOWER(c.email) AS email')
            ->where('c.company = :company')
            ->andWhere('c.deletedAt IS NULL')
            ->andWhere('LOWER(c.email) IN (:emails)')
            ->setParameter('company', $company)
            ->setParameter('emails', $emails)
            ->getQuery()
            ->getScalarResult();

        return array_values(array_unique(array_column($rows, 'email')));
    }
    /**
     * Partners whose registry check is missing or older than `$since`: companies
     * with an identifier (CUI / VAT code), never individuals.
     *
     * @return Client[]
     */
    public function findStaleForVerification(Company $company, \DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.company = :company')
            ->andWhere('c.deletedAt IS NULL')
            ->andWhere('c.type = :type')
            ->setParameter('type', 'company')
            ->andWhere("(c.cui IS NOT NULL AND c.cui != '') OR (c.vatCode IS NOT NULL AND c.vatCode != '')")
            ->andWhere('c.vatStatusCheckedAt IS NULL OR c.vatStatusCheckedAt < :since')
            ->setParameter('company', $company)
            ->setParameter('since', $since)
            ->orderBy('c.vatStatusCheckedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
