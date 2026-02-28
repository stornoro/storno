<?php

namespace App\Service\Report;

use App\Entity\Company;
use Doctrine\ORM\EntityManagerInterface;

class SalesAnalysisService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function generate(Company $company, string $dateFrom, string $dateTo): array
    {
        $conn = $this->entityManager->getConnection();
        $companyId = (string) $company->getId();

        $baseWhere = 'company_id = :companyId AND direction = \'outgoing\' AND status NOT IN (\'draft\', \'cancelled\') AND deleted_at IS NULL';

        // Annual total (current year up to dateTo, plus previous year)
        $currentYear = (int) substr($dateTo, 0, 4);
        $prevYear = $currentYear - 1;

        $annualCurrent = $conn->fetchAssociative(
            "SELECT COALESCE(SUM(total), 0) AS amount
             FROM invoice
             WHERE {$baseWhere}
               AND YEAR(issue_date) = :year AND issue_date <= :dateTo",
            ['companyId' => $companyId, 'year' => $currentYear, 'dateTo' => $dateTo]
        );

        $annualPrev = $conn->fetchAssociative(
            "SELECT COALESCE(SUM(total), 0) AS amount
             FROM invoice
             WHERE {$baseWhere}
               AND YEAR(issue_date) = :year",
            ['companyId' => $companyId, 'year' => $prevYear]
        );

        // Period invoiced
        $periodInvoiced = $conn->fetchAssociative(
            "SELECT COALESCE(SUM(subtotal), 0) AS subtotal,
                    COALESCE(SUM(vat_total), 0) AS vat_total,
                    COALESCE(SUM(total), 0) AS total,
                    COUNT(*) AS cnt
             FROM invoice
             WHERE {$baseWhere}
               AND issue_date >= :dateFrom AND issue_date <= :dateTo",
            ['companyId' => $companyId, 'dateFrom' => $dateFrom, 'dateTo' => $dateTo]
        );

        // Period collected
        $periodCollected = $conn->fetchAssociative(
            "SELECT COALESCE(SUM(subtotal), 0) AS subtotal,
                    COALESCE(SUM(vat_total), 0) AS vat_total,
                    COALESCE(SUM(total), 0) AS total,
                    COUNT(*) AS cnt
             FROM invoice
             WHERE {$baseWhere}
               AND issue_date >= :dateFrom AND issue_date <= :dateTo
               AND paid_at IS NOT NULL",
            ['companyId' => $companyId, 'dateFrom' => $dateFrom, 'dateTo' => $dateTo]
        );

        // Period outstanding
        $periodOutstanding = $conn->fetchAssociative(
            "SELECT COALESCE(SUM(subtotal), 0) AS subtotal,
                    COALESCE(SUM(vat_total), 0) AS vat_total,
                    COALESCE(SUM(total - amount_paid), 0) AS total,
                    COUNT(*) AS cnt
             FROM invoice
             WHERE {$baseWhere}
               AND issue_date >= :dateFrom AND issue_date <= :dateTo
               AND paid_at IS NULL",
            ['companyId' => $companyId, 'dateFrom' => $dateFrom, 'dateTo' => $dateTo]
        );

        // Monthly revenue
        $monthlyRows = $conn->fetchAllAssociative(
            "SELECT DATE_FORMAT(issue_date, '%Y-%m') AS month,
                    COALESCE(SUM(total), 0) AS invoiced,
                    COALESCE(SUM(CASE WHEN paid_at IS NOT NULL THEN total ELSE 0 END), 0) AS collected
             FROM invoice
             WHERE {$baseWhere}
               AND issue_date >= :dateFrom AND issue_date <= :dateTo
             GROUP BY month
             ORDER BY month",
            ['companyId' => $companyId, 'dateFrom' => $dateFrom, 'dateTo' => $dateTo]
        );

        // Recent invoices (last 10)
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
                    COALESCE(SUM(i.total), 0) AS total, COUNT(*) AS cnt
             FROM invoice i
             LEFT JOIN client c ON c.id = i.client_id
             WHERE i.company_id = :companyId AND i.direction = 'outgoing'
               AND i.status NOT IN ('draft', 'cancelled') AND i.deleted_at IS NULL
               AND i.issue_date >= :dateFrom AND i.issue_date <= :dateTo
             GROUP BY i.client_id, client_name
             ORDER BY total DESC
             LIMIT 50",
            ['companyId' => $companyId, 'dateFrom' => $dateFrom, 'dateTo' => $dateTo]
        );

        // Top products
        $topProducts = $conn->fetchAllAssociative(
            "SELECT il.description, il.product_code,
                    COALESCE(SUM(il.line_total), 0) AS total,
                    COALESCE(SUM(il.quantity), 0) AS quantity
             FROM invoice_line il
             INNER JOIN invoice i ON i.id = il.invoice_id
             WHERE i.company_id = :companyId AND i.direction = 'outgoing'
               AND i.status NOT IN ('draft', 'cancelled') AND i.deleted_at IS NULL
               AND i.issue_date >= :dateFrom AND i.issue_date <= :dateTo
             GROUP BY il.description, il.product_code
             ORDER BY total DESC
             LIMIT 50",
            ['companyId' => $companyId, 'dateFrom' => $dateFrom, 'dateTo' => $dateTo]
        );

        return [
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
