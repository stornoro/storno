<?php

namespace App\Service;

use App\Entity\Company;
use App\Enum\DocumentStatus;
use App\Enum\InvoiceDirection;
use Doctrine\ORM\EntityManagerInterface;

class MonthlySummaryService
{
    private const EXCLUDED_STATUSES = [
        DocumentStatus::DRAFT->value,
        DocumentStatus::CANCELLED->value,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function getCompanySummary(Company $company, \DateTimeImmutable $monthStart, \DateTimeImmutable $monthEnd): ?array
    {
        $conn = $this->entityManager->getConnection();
        $companyId = $company->getId()->toRfc4122();
        $currency = $company->getDefaultCurrency();

        // Total invoiced: SUM(total) + COUNT of outgoing invoices issued in the month
        $invoiced = $conn->fetchAssociative(
            'SELECT COALESCE(SUM(i.total), 0) AS total, COUNT(i.id) AS count
             FROM invoice i
             WHERE i.company_id = :companyId
               AND i.direction = :direction
               AND i.status NOT IN (:draft, :cancelled)
               AND i.issue_date >= :monthStart
               AND i.issue_date <= :monthEnd
               AND i.deleted_at IS NULL',
            [
                'companyId' => $companyId,
                'direction' => InvoiceDirection::OUTGOING->value,
                'draft' => DocumentStatus::DRAFT->value,
                'cancelled' => DocumentStatus::CANCELLED->value,
                'monthStart' => $monthStart->format('Y-m-d'),
                'monthEnd' => $monthEnd->format('Y-m-d'),
            ],
        );

        $totalInvoiced = (float) $invoiced['total'];
        $invoiceCount = (int) $invoiced['count'];

        // Total collected: SUM of payments on outgoing invoices with payment_date in the month
        $collected = $conn->fetchAssociative(
            'SELECT COALESCE(SUM(p.amount), 0) AS total, COUNT(p.id) AS count
             FROM payment p
             JOIN invoice i ON p.invoice_id = i.id
             WHERE p.company_id = :companyId
               AND i.direction = :direction
               AND p.payment_date >= :monthStart
               AND p.payment_date <= :monthEnd
               AND i.deleted_at IS NULL',
            [
                'companyId' => $companyId,
                'direction' => InvoiceDirection::OUTGOING->value,
                'monthStart' => $monthStart->format('Y-m-d'),
                'monthEnd' => $monthEnd->format('Y-m-d'),
            ],
        );

        $totalCollected = (float) $collected['total'];
        $paymentCount = (int) $collected['count'];

        // Skip if zero activity
        if ($invoiceCount === 0 && $paymentCount === 0) {
            return null;
        }

        // Collection rate
        $collectionRate = $totalInvoiced > 0 ? round($totalCollected / $totalInvoiced * 100, 1) : 0;

        // Receivables breakdown (snapshot at month end)
        // Only consider outgoing invoices that are NOT fully paid and NOT draft/cancelled
        $monthEndStr = $monthEnd->format('Y-m-d');

        // Restante (overdue): due_date < monthEnd, not fully paid
        $overdue = $conn->fetchAssociative(
            'SELECT COALESCE(SUM(i.total - i.amount_paid), 0) AS total, COUNT(i.id) AS count
             FROM invoice i
             WHERE i.company_id = :companyId
               AND i.direction = :direction
               AND i.status NOT IN (:draft, :cancelled, :paid)
               AND i.due_date < :monthEnd
               AND i.total > i.amount_paid
               AND i.deleted_at IS NULL',
            [
                'companyId' => $companyId,
                'direction' => InvoiceDirection::OUTGOING->value,
                'draft' => DocumentStatus::DRAFT->value,
                'cancelled' => DocumentStatus::CANCELLED->value,
                'paid' => DocumentStatus::PAID->value,
                'monthEnd' => $monthEndStr,
            ],
        );

        // Scadente azi: due_date = monthEnd
        $dueToday = $conn->fetchAssociative(
            'SELECT COALESCE(SUM(i.total - i.amount_paid), 0) AS total, COUNT(i.id) AS count
             FROM invoice i
             WHERE i.company_id = :companyId
               AND i.direction = :direction
               AND i.status NOT IN (:draft, :cancelled, :paid)
               AND i.due_date = :monthEnd
               AND i.total > i.amount_paid
               AND i.deleted_at IS NULL',
            [
                'companyId' => $companyId,
                'direction' => InvoiceDirection::OUTGOING->value,
                'draft' => DocumentStatus::DRAFT->value,
                'cancelled' => DocumentStatus::CANCELLED->value,
                'paid' => DocumentStatus::PAID->value,
                'monthEnd' => $monthEndStr,
            ],
        );

        // Scadente 1-30 zile: due_date between monthEnd+1 and monthEnd+30
        $monthEndPlus1 = $monthEnd->modify('+1 day')->format('Y-m-d');
        $monthEndPlus30 = $monthEnd->modify('+30 days')->format('Y-m-d');

        $dueSoon = $conn->fetchAssociative(
            'SELECT COALESCE(SUM(i.total - i.amount_paid), 0) AS total, COUNT(i.id) AS count
             FROM invoice i
             WHERE i.company_id = :companyId
               AND i.direction = :direction
               AND i.status NOT IN (:draft, :cancelled, :paid)
               AND i.due_date >= :start
               AND i.due_date <= :end
               AND i.total > i.amount_paid
               AND i.deleted_at IS NULL',
            [
                'companyId' => $companyId,
                'direction' => InvoiceDirection::OUTGOING->value,
                'draft' => DocumentStatus::DRAFT->value,
                'cancelled' => DocumentStatus::CANCELLED->value,
                'paid' => DocumentStatus::PAID->value,
                'start' => $monthEndPlus1,
                'end' => $monthEndPlus30,
            ],
        );

        // Scadente >30 zile: due_date > monthEnd+30
        $dueLater = $conn->fetchAssociative(
            'SELECT COALESCE(SUM(i.total - i.amount_paid), 0) AS total, COUNT(i.id) AS count
             FROM invoice i
             WHERE i.company_id = :companyId
               AND i.direction = :direction
               AND i.status NOT IN (:draft, :cancelled, :paid)
               AND i.due_date > :end
               AND i.total > i.amount_paid
               AND i.deleted_at IS NULL',
            [
                'companyId' => $companyId,
                'direction' => InvoiceDirection::OUTGOING->value,
                'draft' => DocumentStatus::DRAFT->value,
                'cancelled' => DocumentStatus::CANCELLED->value,
                'paid' => DocumentStatus::PAID->value,
                'end' => $monthEndPlus30,
            ],
        );

        // Top 5 clients by invoiced amount
        $topClients = $conn->fetchAllAssociative(
            'SELECT i.receiver_name AS name,
                    SUM(i.total) AS invoiced,
                    COALESCE((
                        SELECT SUM(p2.amount) FROM payment p2
                        WHERE p2.invoice_id = i.id
                          AND p2.payment_date >= :monthStart
                          AND p2.payment_date <= :monthEnd
                    ), 0) AS collected
             FROM invoice i
             WHERE i.company_id = :companyId
               AND i.direction = :direction
               AND i.status NOT IN (:draft, :cancelled)
               AND i.issue_date >= :monthStart
               AND i.issue_date <= :monthEnd
               AND i.deleted_at IS NULL
             GROUP BY i.receiver_name
             ORDER BY invoiced DESC
             LIMIT 5',
            [
                'companyId' => $companyId,
                'direction' => InvoiceDirection::OUTGOING->value,
                'draft' => DocumentStatus::DRAFT->value,
                'cancelled' => DocumentStatus::CANCELLED->value,
                'monthStart' => $monthStart->format('Y-m-d'),
                'monthEnd' => $monthEnd->format('Y-m-d'),
            ],
        );

        // Month-over-month comparison
        $prevMonthStart = $monthStart->modify('-1 month');
        $prevMonthEnd = $prevMonthStart->modify('last day of this month');

        $prevInvoiced = $conn->fetchAssociative(
            'SELECT COALESCE(SUM(i.total), 0) AS total
             FROM invoice i
             WHERE i.company_id = :companyId
               AND i.direction = :direction
               AND i.status NOT IN (:draft, :cancelled)
               AND i.issue_date >= :monthStart
               AND i.issue_date <= :monthEnd
               AND i.deleted_at IS NULL',
            [
                'companyId' => $companyId,
                'direction' => InvoiceDirection::OUTGOING->value,
                'draft' => DocumentStatus::DRAFT->value,
                'cancelled' => DocumentStatus::CANCELLED->value,
                'monthStart' => $prevMonthStart->format('Y-m-d'),
                'monthEnd' => $prevMonthEnd->format('Y-m-d'),
            ],
        );

        $prevCollected = $conn->fetchAssociative(
            'SELECT COALESCE(SUM(p.amount), 0) AS total
             FROM payment p
             JOIN invoice i ON p.invoice_id = i.id
             WHERE p.company_id = :companyId
               AND i.direction = :direction
               AND p.payment_date >= :monthStart
               AND p.payment_date <= :monthEnd
               AND i.deleted_at IS NULL',
            [
                'companyId' => $companyId,
                'direction' => InvoiceDirection::OUTGOING->value,
                'monthStart' => $prevMonthStart->format('Y-m-d'),
                'monthEnd' => $prevMonthEnd->format('Y-m-d'),
            ],
        );

        $prevOverdue = $conn->fetchAssociative(
            'SELECT COALESCE(SUM(i.total - i.amount_paid), 0) AS total
             FROM invoice i
             WHERE i.company_id = :companyId
               AND i.direction = :direction
               AND i.status NOT IN (:draft, :cancelled, :paid)
               AND i.due_date < :monthEnd
               AND i.total > i.amount_paid
               AND i.deleted_at IS NULL',
            [
                'companyId' => $companyId,
                'direction' => InvoiceDirection::OUTGOING->value,
                'draft' => DocumentStatus::DRAFT->value,
                'cancelled' => DocumentStatus::CANCELLED->value,
                'paid' => DocumentStatus::PAID->value,
                'monthEnd' => $prevMonthEnd->format('Y-m-d'),
            ],
        );

        $prevInvoicedTotal = (float) $prevInvoiced['total'];
        $prevCollectedTotal = (float) $prevCollected['total'];
        $prevOverdueTotal = (float) $prevOverdue['total'];
        $overdueTotal = (float) $overdue['total'];

        $avatarGradients = [
            'linear-gradient(135deg, #6366f1, #8b5cf6)',
            'linear-gradient(135deg, #f59e0b, #fbbf24)',
            'linear-gradient(135deg, #22c55e, #4ade80)',
            'linear-gradient(135deg, #ef4444, #f87171)',
            'linear-gradient(135deg, #3b82f6, #60a5fa)',
        ];

        return [
            'totalInvoiced' => $totalInvoiced,
            'totalInvoicedFormatted' => number_format($totalInvoiced, 0, ',', '.'),
            'invoiceCount' => $invoiceCount,
            'totalCollected' => $totalCollected,
            'totalCollectedFormatted' => number_format($totalCollected, 0, ',', '.'),
            'paymentCount' => $paymentCount,
            'collectionRate' => $collectionRate,
            'currency' => $currency,
            'receivables' => [
                [
                    'label' => 'Restante',
                    'total' => (float) $overdue['total'],
                    'totalFormatted' => number_format((float) $overdue['total'], 0, ',', '.'),
                    'count' => (int) $overdue['count'],
                    'color' => '#ef4444',
                ],
                [
                    'label' => 'Scadente in 1-30 zile',
                    'total' => (float) $dueSoon['total'],
                    'totalFormatted' => number_format((float) $dueSoon['total'], 0, ',', '.'),
                    'count' => (int) $dueSoon['count'],
                    'color' => '#f59e0b',
                ],
                [
                    'label' => 'Scadente azi',
                    'total' => (float) $dueToday['total'],
                    'totalFormatted' => number_format((float) $dueToday['total'], 0, ',', '.'),
                    'count' => (int) $dueToday['count'],
                    'color' => '#ea580c',
                ],
                [
                    'label' => 'Scadente >30 zile',
                    'total' => (float) $dueLater['total'],
                    'totalFormatted' => number_format((float) $dueLater['total'], 0, ',', '.'),
                    'count' => (int) $dueLater['count'],
                    'color' => '#6b7280',
                ],
            ],
            'topClients' => array_map(function (array $client, int $index) use ($currency, $avatarGradients) {
                $name = $client['name'] ?: 'Necunoscut';
                return [
                    'name' => $name,
                    'initial' => mb_strtoupper(mb_substr($name, 0, 1)),
                    'avatarGradient' => $avatarGradients[$index % count($avatarGradients)],
                    'invoiced' => number_format((float) $client['invoiced'], 0, ',', '.'),
                    'collected' => number_format((float) $client['collected'], 0, ',', '.'),
                    'currency' => $currency,
                ];
            }, $topClients, array_keys($topClients)),
            'comparison' => [
                'invoiced' => $this->computeChange($totalInvoiced, $prevInvoicedTotal),
                'collected' => $this->computeChange($totalCollected, $prevCollectedTotal),
                'overdue' => $this->computeChange($overdueTotal, $prevOverdueTotal),
            ],
        ];
    }

    private function computeChange(float $current, float $previous): array
    {
        if ($previous == 0 && $current == 0) {
            return ['percent' => 0, 'direction' => 'flat', 'label' => '0%'];
        }

        if ($previous == 0) {
            return ['percent' => 100, 'direction' => 'up', 'label' => '+100%'];
        }

        $change = round(($current - $previous) / $previous * 100);

        if ($change > 0) {
            return ['percent' => $change, 'direction' => 'up', 'label' => '+' . $change . '%'];
        }

        if ($change < 0) {
            return ['percent' => abs($change), 'direction' => 'down', 'label' => $change . '%'];
        }

        return ['percent' => 0, 'direction' => 'flat', 'label' => '0%'];
    }
}
