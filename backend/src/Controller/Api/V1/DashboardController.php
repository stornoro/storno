<?php

namespace App\Controller\Api\V1;

use App\Enum\DocumentStatus;
use App\Enum\InvoiceDirection;
use App\Repository\ClientRepository;
use App\Repository\CompanyRepository;
use App\Repository\InvoiceRepository;
use App\Repository\ProductRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Service\ExchangeRateService;
use App\Service\PaymentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/dashboard')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly ClientRepository $clientRepository,
        private readonly ProductRepository $productRepository,
        private readonly OrganizationContext $organizationContext,
        private readonly CompanyRepository $companyRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentService $paymentService,
        private readonly ExchangeRateService $exchangeRateService,
    ) {}

    #[Route('/stats', methods: ['GET'])]
    public function stats(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::REPORT_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $conn = $this->entityManager->getConnection();
        $companyId = (string) $company->getId();

        // Optional date filtering
        $dateFrom = $request->query->get('dateFrom');
        $dateTo = $request->query->get('dateTo');
        $hasDateFilter = $dateFrom || $dateTo;

        $dateFilter = '';
        $dateParams = [];
        if ($dateFrom) {
            $dateFilter .= ' AND issue_date >= :dateFrom';
            $dateParams['dateFrom'] = $dateFrom;
        }
        if ($dateTo) {
            $dateFilter .= ' AND issue_date <= :dateTo';
            $dateParams['dateTo'] = $dateTo;
        }

        $defaultCurrency = $company->getDefaultCurrency() ?? 'RON';
        $defaultRate = $this->exchangeRateService->getRate($defaultCurrency) ?? 1.0;
        $baseParams = array_merge(['companyId' => $companyId], $dateParams);
        $amountParams = array_merge($baseParams, ['defaultCurrency' => $defaultCurrency, 'defaultRate' => $defaultRate]);

        // Build fallback BNR rate lookup for currencies with NULL exchange_rate
        $fallbackRateSql = $this->exchangeRateService->buildFallbackRateSql($conn, $companyId, $defaultCurrency);

        // Cancelled invoices are fiscally void — exclude them from all aggregate
        // counts/totals. The byStatus breakdown below keeps them so the UI can
        // still show "X cancelled" as its own bucket.
        $activeFilter = " AND status != 'cancelled'";

        // Counts by direction
        $directionCounts = $conn->fetchAllAssociative(
            'SELECT direction, COUNT(*) as cnt FROM invoice WHERE company_id = :companyId AND deleted_at IS NULL' . $activeFilter . $dateFilter . ' GROUP BY direction',
            $baseParams
        );

        $incoming = 0;
        $outgoing = 0;
        foreach ($directionCounts as $row) {
            if ($row['direction'] === 'incoming') $incoming = (int) $row['cnt'];
            if ($row['direction'] === 'outgoing') $outgoing = (int) $row['cnt'];
        }

        // Counts by status
        $statusCounts = $conn->fetchAllAssociative(
            'SELECT status, COUNT(*) as cnt FROM invoice WHERE company_id = :companyId AND deleted_at IS NULL' . $dateFilter . ' GROUP BY status',
            $baseParams
        );
        $byStatus = [];
        foreach ($statusCounts as $row) {
            $byStatus[$row['status']] = (int) $row['cnt'];
        }

        // SQL expression: convert amount to default currency
        // Uses stored exchange_rate when available, falls back to current BNR rate
        $convertTotal = "CASE WHEN currency = :defaultCurrency THEN total ELSE total * COALESCE(exchange_rate, $fallbackRateSql) / :defaultRate END";
        $convertVat = "CASE WHEN currency = :defaultCurrency THEN vat_total ELSE vat_total * COALESCE(exchange_rate, $fallbackRateSql) / :defaultRate END";

        // Total amounts (converted to default currency)
        $totals = $conn->fetchAssociative(
            "SELECT COALESCE(SUM($convertTotal), 0) as total_amount, COALESCE(SUM($convertVat), 0) as total_vat FROM invoice WHERE company_id = :companyId AND deleted_at IS NULL" . $activeFilter . $dateFilter,
            $amountParams
        );

        // Client count: when a period is selected, count distinct clients with
        // non-cancelled invoices in that range (transactional metric matching the
        // rest of the dashboard). Without a period, fall back to the address-book
        // total so "All Time" reflects everyone the user has on file.
        if ($hasDateFilter) {
            $clientCount = $conn->fetchOne(
                'SELECT COUNT(DISTINCT client_id) FROM invoice WHERE company_id = :companyId AND deleted_at IS NULL AND client_id IS NOT NULL' . $activeFilter . $dateFilter,
                $baseParams
            );
        } else {
            $clientCount = $conn->fetchOne(
                'SELECT COUNT(*) FROM client WHERE company_id = :companyId AND deleted_at IS NULL',
                ['companyId' => $companyId]
            );
        }

        // Product count: same hybrid — distinct products billed in period, or
        // catalogue total when no period is selected.
        if ($hasDateFilter) {
            $productDateFilter = '';
            if ($dateFrom) {
                $productDateFilter .= ' AND i.issue_date >= :dateFrom';
            }
            if ($dateTo) {
                $productDateFilter .= ' AND i.issue_date <= :dateTo';
            }
            $productCount = $conn->fetchOne(
                "SELECT COUNT(DISTINCT il.product_id) FROM invoice_line il INNER JOIN invoice i ON il.invoice_id = i.id WHERE i.company_id = :companyId AND i.deleted_at IS NULL AND il.product_id IS NOT NULL AND i.status != 'cancelled'" . $productDateFilter,
                $baseParams
            );
        } else {
            $productCount = $conn->fetchOne(
                'SELECT COUNT(*) FROM product WHERE company_id = :companyId AND deleted_at IS NULL',
                ['companyId' => $companyId]
            );
        }

        // Monthly totals (last 12 months, grouped by direction, converted to default currency)
        $monthlyRows = $conn->fetchAllAssociative(
            "SELECT DATE_FORMAT(issue_date, '%Y-%m') AS month, direction, COALESCE(SUM($convertTotal), 0) AS amount
             FROM invoice
             WHERE company_id = :companyId AND deleted_at IS NULL" . $activeFilter . " AND issue_date >= (CURRENT_DATE - INTERVAL 12 MONTH)" . $dateFilter . "
             GROUP BY month, direction
             ORDER BY month",
            $amountParams
        );

        $monthlyMap = [];
        foreach ($monthlyRows as $row) {
            $m = $row['month'];
            if (!isset($monthlyMap[$m])) {
                $monthlyMap[$m] = ['month' => $m, 'incoming' => '0.00', 'outgoing' => '0.00'];
            }
            if ($row['direction'] === 'incoming') {
                $monthlyMap[$m]['incoming'] = $row['amount'];
            } elseif ($row['direction'] === 'outgoing') {
                $monthlyMap[$m]['outgoing'] = $row['amount'];
            }
        }
        $monthlyTotals = array_values($monthlyMap);

        // Amounts by direction (converted to default currency)
        $directionAmounts = $conn->fetchAllAssociative(
            "SELECT direction, COALESCE(SUM($convertTotal), 0) AS amount FROM invoice WHERE company_id = :companyId AND deleted_at IS NULL" . $activeFilter . $dateFilter . ' GROUP BY direction',
            $amountParams
        );
        $amountsByDirection = ['incoming' => '0.00', 'outgoing' => '0.00'];
        foreach ($directionAmounts as $row) {
            if ($row['direction'] === 'incoming') $amountsByDirection['incoming'] = $row['amount'];
            if ($row['direction'] === 'outgoing') $amountsByDirection['outgoing'] = $row['amount'];
        }

        // Recent activity (last 10 synced, filtered by date if provided).
        // Exclude cancelled invoices to match the rest of the dashboard.
        $qb = $this->invoiceRepository->createQueryBuilder('i')
            ->where('i.company = :company')
            ->andWhere('i.status != :cancelledStatus')
            ->setParameter('company', $company)
            ->setParameter('cancelledStatus', DocumentStatus::CANCELLED)
            ->orderBy('i.syncedAt', 'DESC')
            ->setMaxResults(10);

        if ($dateFrom) {
            $qb->andWhere('i.issueDate >= :dateFrom')
               ->setParameter('dateFrom', new \DateTime($dateFrom));
        }
        if ($dateTo) {
            $qb->andWhere('i.issueDate <= :dateTo')
               ->setParameter('dateTo', new \DateTime($dateTo));
        }

        $recentInvoices = $qb->getQuery()->getResult();

        $recent = array_map(function ($inv) {
            return [
                'id' => (string) $inv->getId(),
                'number' => $inv->getNumber(),
                'direction' => $inv->getDirection()?->value,
                'status' => $inv->getStatus()->value,
                'total' => $inv->getTotal(),
                'currency' => $inv->getCurrency(),
                'senderName' => $inv->getSenderName(),
                'receiverName' => $inv->getReceiverName(),
                'issueDate' => $inv->getIssueDate()?->format('Y-m-d'),
                'syncedAt' => $inv->getSyncedAt()?->format('c'),
                'paidAt' => $inv->getPaidAt()?->format('c'),
            ];
        }, $recentInvoices);

        // Payment summary (converted to default currency)
        $paymentSummary = $this->paymentService->getPaymentSummary($company, $dateFrom, $dateTo, $defaultCurrency, $defaultRate, $fallbackRateSql);

        $response = $this->json([
            'invoices' => [
                'total' => $incoming + $outgoing,
                'incoming' => $incoming,
                'outgoing' => $outgoing,
            ],
            'byStatus' => $byStatus,
            'amounts' => [
                'total' => $totals['total_amount'] ?? '0.00',
                'vat' => $totals['total_vat'] ?? '0.00',
            ],
            'clientCount' => (int) $clientCount,
            'productCount' => (int) $productCount,
            'lastSyncedAt' => $company->getLastSyncedAt()?->format('c'),
            'syncEnabled' => $company->isSyncEnabled(),
            'recentActivity' => $recent,
            'monthlyTotals' => $monthlyTotals,
            'amountsByDirection' => $amountsByDirection,
            'payments' => $paymentSummary,
            'currency' => $defaultCurrency,
        ]);

        // Skip cache when date params are present (filtered data)
        if (!$hasDateFilter) {
            $response->setMaxAge(60);
        }
        $response->setPrivate();
        $response->setVary(['X-Company', 'Authorization']);

        return $response;
    }

    #[Route('/top-clients-revenue', methods: ['GET'])]
    public function topClientsRevenue(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        $conn = $this->entityManager->getConnection();
        $companyId = (string) $company->getId();
        $defaultCurrency = $company->getDefaultCurrency() ?? 'RON';
        $defaultRate = $this->exchangeRateService->getRate($defaultCurrency) ?? 1.0;

        $limit = min((int) ($request->query->get('limit', 5)), 20);
        if ($limit < 1) {
            $limit = 5;
        }

        $dateFrom = $request->query->get('dateFrom');
        $dateTo = $request->query->get('dateTo');

        $dateFilter = '';
        $params = [
            'companyId' => $companyId,
            'defaultCurrency' => $defaultCurrency,
            'defaultRate' => $defaultRate,
        ];

        if ($dateFrom) {
            $dateFilter .= ' AND i.issue_date >= :dateFrom';
            $params['dateFrom'] = $dateFrom;
        }
        if ($dateTo) {
            $dateFilter .= ' AND i.issue_date <= :dateTo';
            $params['dateTo'] = $dateTo;
        }

        $fallbackRateSql = $this->exchangeRateService->buildFallbackRateSql($conn, $companyId, $defaultCurrency);
        $convertTotal = "CASE WHEN i.currency = :defaultCurrency THEN i.total ELSE i.total * COALESCE(i.exchange_rate, $fallbackRateSql) / :defaultRate END";

        $rows = $conn->fetchAllAssociative(
            "SELECT c.id, c.name, COALESCE(SUM($convertTotal), 0) AS amount, COUNT(i.id) AS invoice_count
             FROM invoice i
             INNER JOIN client c ON i.client_id = c.id
             WHERE i.company_id = :companyId
               AND i.deleted_at IS NULL
               AND i.status != 'cancelled'
               AND i.direction = 'outgoing'
               AND i.client_id IS NOT NULL
               $dateFilter
             GROUP BY c.id, c.name
             ORDER BY amount DESC
             LIMIT $limit",
            $params
        );

        $clients = array_map(fn (array $row) => [
            'id' => $row['id'],
            'name' => $row['name'],
            'amount' => number_format((float) $row['amount'], 2, '.', ''),
            'invoiceCount' => (int) $row['invoice_count'],
        ], $rows);

        $response = $this->json([
            'currency' => $defaultCurrency,
            'clients' => $clients,
        ]);
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/top-products-revenue', methods: ['GET'])]
    public function topProductsRevenue(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        $conn = $this->entityManager->getConnection();
        $companyId = (string) $company->getId();
        $defaultCurrency = $company->getDefaultCurrency() ?? 'RON';
        $defaultRate = $this->exchangeRateService->getRate($defaultCurrency) ?? 1.0;

        $limit = min((int) ($request->query->get('limit', 5)), 20);
        if ($limit < 1) {
            $limit = 5;
        }

        $dateFrom = $request->query->get('dateFrom');
        $dateTo = $request->query->get('dateTo');

        $dateFilter = '';
        $params = [
            'companyId' => $companyId,
            'defaultCurrency' => $defaultCurrency,
            'defaultRate' => $defaultRate,
        ];

        if ($dateFrom) {
            $dateFilter .= ' AND i.issue_date >= :dateFrom';
            $params['dateFrom'] = $dateFrom;
        }
        if ($dateTo) {
            $dateFilter .= ' AND i.issue_date <= :dateTo';
            $params['dateTo'] = $dateTo;
        }

        // line_total is already per-line; multiply by invoice exchange_rate to convert to default currency
        $convertLineTotal = "CASE WHEN i.currency = :defaultCurrency THEN il.line_total ELSE il.line_total * COALESCE(i.exchange_rate, (SELECT r.rate FROM exchange_rate r WHERE r.currency_code = i.currency ORDER BY r.date DESC LIMIT 1)) / :defaultRate END";

        // Use the same fallback rate SQL approach as the rest of the dashboard
        $fallbackRateSql = $this->exchangeRateService->buildFallbackRateSql($conn, $companyId, $defaultCurrency);
        $convertLineTotal = "CASE WHEN i.currency = :defaultCurrency THEN il.line_total ELSE il.line_total * COALESCE(i.exchange_rate, $fallbackRateSql) / :defaultRate END";

        $rows = $conn->fetchAllAssociative(
            "SELECT p.id, p.name, COALESCE(SUM($convertLineTotal), 0) AS amount, COALESCE(SUM(il.quantity), 0) AS quantity
             FROM invoice_line il
             INNER JOIN invoice i ON il.invoice_id = i.id
             INNER JOIN product p ON il.product_id = p.id
             WHERE i.company_id = :companyId
               AND i.deleted_at IS NULL
               AND i.status != 'cancelled'
               AND i.direction = 'outgoing'
               AND il.product_id IS NOT NULL
               AND p.deleted_at IS NULL
               $dateFilter
             GROUP BY p.id, p.name
             ORDER BY amount DESC
             LIMIT $limit",
            $params
        );

        $products = array_map(fn (array $row) => [
            'id' => $row['id'],
            'name' => $row['name'],
            'amount' => number_format((float) $row['amount'], 2, '.', ''),
            'quantity' => number_format((float) $row['quantity'], 3, '.', ''),
        ], $rows);

        $response = $this->json([
            'currency' => $defaultCurrency,
            'products' => $products,
        ]);
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/top-outstanding-clients', methods: ['GET'])]
    public function topOutstandingClients(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        $conn = $this->entityManager->getConnection();
        $companyId = (string) $company->getId();
        $defaultCurrency = $company->getDefaultCurrency() ?? 'RON';
        $defaultRate = $this->exchangeRateService->getRate($defaultCurrency) ?? 1.0;

        $limit = min((int) ($request->query->get('limit', 5)), 20);
        if ($limit < 1) {
            $limit = 5;
        }

        $fallbackRateSql = $this->exchangeRateService->buildFallbackRateSql($conn, $companyId, $defaultCurrency);
        $convertOutstanding = "CASE WHEN i.currency = :defaultCurrency THEN (i.total - i.amount_paid) ELSE (i.total - i.amount_paid) * COALESCE(i.exchange_rate, $fallbackRateSql) / :defaultRate END";

        $rows = $conn->fetchAllAssociative(
            "SELECT c.id, c.name, COALESCE(SUM($convertOutstanding), 0) AS amount, COUNT(i.id) AS invoice_count
             FROM invoice i
             INNER JOIN client c ON i.client_id = c.id
             WHERE i.company_id = :companyId
               AND i.deleted_at IS NULL
               AND i.status NOT IN ('cancelled', 'draft')
               AND i.direction = 'outgoing'
               AND i.paid_at IS NULL
               AND i.client_id IS NOT NULL
             GROUP BY c.id, c.name
             HAVING amount > 0
             ORDER BY amount DESC
             LIMIT $limit",
            [
                'companyId' => $companyId,
                'defaultCurrency' => $defaultCurrency,
                'defaultRate' => $defaultRate,
            ]
        );

        $clients = array_map(fn (array $row) => [
            'id' => $row['id'],
            'name' => $row['name'],
            'amount' => number_format((float) $row['amount'], 2, '.', ''),
            'invoiceCount' => (int) $row['invoice_count'],
        ], $rows);

        $response = $this->json([
            'currency' => $defaultCurrency,
            'clients' => $clients,
        ]);
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function resolveCompany(Request $request): ?\App\Entity\Company
    {
        return $this->organizationContext->resolveCompany($request);
    }
}
