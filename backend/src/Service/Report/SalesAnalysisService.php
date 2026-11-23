<?php

namespace App\Service\Report;

use App\Entity\Company;
use App\Service\Cache\CompanyStatsCache;
use App\Service\ExchangeRateService;
use Doctrine\ORM\EntityManagerInterface;

class SalesAnalysisService
{
    /**
     * Whole-company aggregates live until the company's next write moves the cache version; the
     * TTL only bounds writes that bypass the ORM.
     */
    private const STATS_TTL = 600;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ExchangeRateService $exchangeRateService,
        private readonly ?CompanyStatsCache $statsCache = null,
    ) {}

    public function generate(Company $company, string $dateFrom, string $dateTo): array
    {
        $companyId = (string) $company->getId();
        $defaultCurrency = $company->getDefaultCurrency() ?? 'RON';
        $defaultRate = $this->exchangeRateService->getRate($defaultCurrency) ?? 1.0;

        if ($this->statsCache === null) {
            return $this->compute($companyId, $dateFrom, $dateTo, $defaultCurrency, $defaultRate);
        }

        // The fallback rates change daily, so the day is part of the key.
        $key = 'sales.analysis|' . json_encode([$dateFrom, $dateTo, $defaultCurrency, $defaultRate, date('Y-m-d')]);

        return $this->statsCache->get($companyId, $key, fn () => $this->compute($companyId, $dateFrom, $dateTo, $defaultCurrency, $defaultRate), self::STATS_TTL);
    }

    private function compute(string $companyId, string $dateFrom, string $dateTo, string $defaultCurrency, float $defaultRate): array
    {
        $conn = $this->entityManager->getConnection();
        $currencies = array_values(array_unique(array_merge([], ...array_values($this->exchangeRateService->getInvoiceCurrencies($conn, $companyId, $defaultCurrency)))));
        $fallbackRateSql = $this->exchangeRateService->buildFallbackRateSqlForCurrencies($currencies);
        $fallbackRateSqlI = $this->exchangeRateService->buildFallbackRateSqlForCurrencies($currencies, 'i.currency');

        // SQL conversion expressions
        $convertTotal = "CASE WHEN currency = :defaultCurrency THEN total ELSE total * COALESCE(exchange_rate, $fallbackRateSql) / :defaultRate END";
        $convertSubtotal = "CASE WHEN currency = :defaultCurrency THEN subtotal ELSE subtotal * COALESCE(exchange_rate, $fallbackRateSql) / :defaultRate END";
        $convertVat = "CASE WHEN currency = :defaultCurrency THEN vat_total ELSE vat_total * COALESCE(exchange_rate, $fallbackRateSql) / :defaultRate END";
        $convertOutstanding = "CASE WHEN currency = :defaultCurrency THEN (total - amount_paid) ELSE (total - amount_paid) * COALESCE(exchange_rate, $fallbackRateSql) / :defaultRate END";

        // Aliased versions (for queries with table alias i.)
        $iConvertTotal = "CASE WHEN i.currency = :defaultCurrency THEN i.total ELSE i.total * COALESCE(i.exchange_rate, $fallbackRateSqlI) / :defaultRate END";
        $ilConvertLineTotal = "CASE WHEN i.currency = :defaultCurrency THEN il.line_total ELSE il.line_total * COALESCE(i.exchange_rate, $fallbackRateSqlI) / :defaultRate END";

        $baseWhere = 'company_id = :companyId AND direction = \'outgoing\' AND status NOT IN (\'draft\', \'cancelled\') AND deleted_at IS NULL';
        $amountBase = ['companyId' => $companyId, 'defaultCurrency' => $defaultCurrency, 'defaultRate' => $defaultRate];

        // Annual total (current year up to dateTo, plus previous year)
        $currentYear = (int) substr($dateTo, 0, 4);
        $prevYear = $currentYear - 1;

        // Year bounds as a date range, so the (company_id, issue_date) index applies.
        $annualCurrent = $conn->fetchAssociative(
            "SELECT COALESCE(SUM($convertTotal), 0) AS amount
             FROM invoice
             WHERE {$baseWhere}
               AND issue_date >= :yearStart AND issue_date < :yearEnd AND issue_date <= :dateTo",
            array_merge($amountBase, ['yearStart' => $currentYear . '-01-01', 'yearEnd' => ($currentYear + 1) . '-01-01', 'dateTo' => $dateTo])
        );

        $annualPrev = $conn->fetchAssociative(
            "SELECT COALESCE(SUM($convertTotal), 0) AS amount
             FROM invoice
             WHERE {$baseWhere}
               AND issue_date >= :yearStart AND issue_date < :yearEnd",
            array_merge($amountBase, ['yearStart' => $prevYear . '-01-01', 'yearEnd' => $currentYear . '-01-01'])
        );

        // Period invoiced
        $periodInvoiced = $conn->fetchAssociative(
            "SELECT COALESCE(SUM($convertSubtotal), 0) AS subtotal,
                    COALESCE(SUM($convertVat), 0) AS vat_total,
                    COALESCE(SUM($convertTotal), 0) AS total,
                    COUNT(*) AS cnt
             FROM invoice
             WHERE {$baseWhere}
               AND issue_date >= :dateFrom AND issue_date <= :dateTo",
            array_merge($amountBase, ['dateFrom' => $dateFrom, 'dateTo' => $dateTo])
        );

        // Period collected
        $periodCollected = $conn->fetchAssociative(
            "SELECT COALESCE(SUM($convertSubtotal), 0) AS subtotal,
                    COALESCE(SUM($convertVat), 0) AS vat_total,
                    COALESCE(SUM($convertTotal), 0) AS total,
                    COUNT(*) AS cnt
             FROM invoice
             WHERE {$baseWhere}
               AND issue_date >= :dateFrom AND issue_date <= :dateTo
               AND paid_at IS NOT NULL",
            array_merge($amountBase, ['dateFrom' => $dateFrom, 'dateTo' => $dateTo])
        );

        // Period outstanding
        $periodOutstanding = $conn->fetchAssociative(
            "SELECT COALESCE(SUM($convertSubtotal), 0) AS subtotal,
                    COALESCE(SUM($convertVat), 0) AS vat_total,
                    COALESCE(SUM($convertOutstanding), 0) AS total,
                    COUNT(*) AS cnt
             FROM invoice
             WHERE {$baseWhere}
               AND issue_date >= :dateFrom AND issue_date <= :dateTo
               AND paid_at IS NULL",
            array_merge($amountBase, ['dateFrom' => $dateFrom, 'dateTo' => $dateTo])
        );

        // Monthly revenue
        $monthlyRows = $conn->fetchAllAssociative(
            "SELECT DATE_FORMAT(issue_date, '%Y-%m') AS month,
                    COALESCE(SUM($convertTotal), 0) AS invoiced,
                    COALESCE(SUM(CASE WHEN paid_at IS NOT NULL THEN ($convertTotal) ELSE 0 END), 0) AS collected
             FROM invoice
             WHERE {$baseWhere}
               AND issue_date >= :dateFrom AND issue_date <= :dateTo
             GROUP BY month
             ORDER BY month",
            array_merge($amountBase, ['dateFrom' => $dateFrom, 'dateTo' => $dateTo])
        );

        // Recent invoices (last 10) — individual rows, no conversion needed
        $recentInvoices = $conn->fetchAllAssociative(
            "SELECT i.id, i.number, i.issue_date, i.total, i.currency, i.status, i.paid_at,
                    COALESCE(c.name, i.receiver_name) AS client_name
             FROM invoice i
             LEFT JOIN client c ON c.id = i.client_id
             WHERE i.company_id = :companyId AND i.direction = 'outgoing'
               AND i.status NOT IN ('draft', 'cancelled') AND i.deleted_at IS NULL
               AND i.issue_date >= :dateFrom AND i.issue_date <= :dateTo
             ORDER BY i.issue_date DESC, i.created_at DESC
             LIMIT 10",
            ['companyId' => $companyId, 'dateFrom' => $dateFrom, 'dateTo' => $dateTo]
        );

        // Top clients
        $topClients = $conn->fetchAllAssociative(
            "SELECT i.client_id, COALESCE(c.name, i.receiver_name) AS client_name,
                    COALESCE(SUM($iConvertTotal), 0) AS total, COUNT(*) AS cnt
             FROM invoice i
             LEFT JOIN client c ON c.id = i.client_id
             WHERE i.company_id = :companyId AND i.direction = 'outgoing'
               AND i.status NOT IN ('draft', 'cancelled') AND i.deleted_at IS NULL
               AND i.issue_date >= :dateFrom AND i.issue_date <= :dateTo
             GROUP BY i.client_id, client_name
             ORDER BY total DESC
             LIMIT 50",
            array_merge($amountBase, ['dateFrom' => $dateFrom, 'dateTo' => $dateTo])
        );

        // Top products
        $topProducts = $conn->fetchAllAssociative(
            "SELECT il.description, il.product_code,
                    COALESCE(SUM($ilConvertLineTotal), 0) AS total,
                    COALESCE(SUM(il.quantity), 0) AS quantity
             FROM invoice_line il
             INNER JOIN invoice i ON i.id = il.invoice_id
             WHERE i.company_id = :companyId AND i.direction = 'outgoing'
               AND i.status NOT IN ('draft', 'cancelled') AND i.deleted_at IS NULL
               AND i.issue_date >= :dateFrom AND i.issue_date <= :dateTo
             GROUP BY il.description, il.product_code
             ORDER BY total DESC
             LIMIT 50",
            array_merge($amountBase, ['dateFrom' => $dateFrom, 'dateTo' => $dateTo])
        );

        return [
            'currency' => $defaultCurrency,
            'period' => [
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
            ],
            'kpiSummary' => [
                'annualTotal' => [
                    'amount' => $annualCurrent['amount'],
                    'year' => $currentYear,
                    'prevAmount' => $annualPrev['amount'],
                    'prevYear' => $prevYear,
                ],
                'periodInvoiced' => [
                    'subtotal' => $periodInvoiced['subtotal'],
                    'vatTotal' => $periodInvoiced['vat_total'],
                    'total' => $periodInvoiced['total'],
                    'count' => (int) $periodInvoiced['cnt'],
                ],
                'periodCollected' => [
                    'subtotal' => $periodCollected['subtotal'],
                    'vatTotal' => $periodCollected['vat_total'],
                    'total' => $periodCollected['total'],
                    'count' => (int) $periodCollected['cnt'],
                ],
                'periodOutstanding' => [
                    'subtotal' => $periodOutstanding['subtotal'],
                    'vatTotal' => $periodOutstanding['vat_total'],
                    'total' => $periodOutstanding['total'],
                    'count' => (int) $periodOutstanding['cnt'],
                ],
            ],
            'monthlyRevenue' => array_map(fn ($row) => [
                'month' => $row['month'],
                'invoiced' => $row['invoiced'],
                'collected' => $row['collected'],
            ], $monthlyRows),
            'recentInvoices' => array_map(fn ($row) => [
                'id' => $row['id'],
                'number' => $row['number'],
                'issueDate' => $row['issue_date'],
                'clientName' => $row['client_name'],
                'total' => $row['total'],
                'currency' => $row['currency'],
                'status' => $row['status'],
                'paidAt' => $row['paid_at'],
            ], $recentInvoices),
            'topClients' => array_map(fn ($row) => [
                'clientId' => $row['client_id'],
                'clientName' => $row['client_name'],
                'total' => $row['total'],
                'count' => (int) $row['cnt'],
            ], $topClients),
            'topProducts' => array_map(fn ($row) => [
                'description' => $row['description'],
                'productCode' => $row['product_code'],
                'total' => $row['total'],
                'quantity' => $row['quantity'],
            ], $topProducts),
        ];
    }
}
