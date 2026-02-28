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

        $baseParams = array_merge(['companyId' => $companyId], $dateParams);

        // Counts by direction
        $directionCounts = $conn->fetchAllAssociative(
            'SELECT direction, COUNT(*) as cnt FROM invoice WHERE company_id = :companyId AND deleted_at IS NULL' . $dateFilter . ' GROUP BY direction',
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

        // Total amounts
        $totals = $conn->fetchAssociative(
            'SELECT COALESCE(SUM(total), 0) as total_amount, COALESCE(SUM(vat_total), 0) as total_vat FROM invoice WHERE company_id = :companyId AND deleted_at IS NULL' . $dateFilter,
            $baseParams
        );

        // Client count (unfiltered — entity count, not transactional)
        $clientCount = $conn->fetchOne(
            'SELECT COUNT(*) FROM client WHERE company_id = :companyId AND deleted_at IS NULL',
            ['companyId' => $companyId]
        );

        // Product count (unfiltered — entity count, not transactional)
        $productCount = $conn->fetchOne(
            'SELECT COUNT(*) FROM product WHERE company_id = :companyId AND deleted_at IS NULL',
            ['companyId' => $companyId]
        );

        // Monthly totals (last 12 months, grouped by direction)
        $monthlyRows = $conn->fetchAllAssociative(
            "SELECT DATE_FORMAT(issue_date, '%Y-%m') AS month, direction, COALESCE(SUM(total), 0) AS amount
             FROM invoice
             WHERE company_id = :companyId AND deleted_at IS NULL AND issue_date >= (CURRENT_DATE - INTERVAL 12 MONTH)" . $dateFilter . "
             GROUP BY month, direction
             ORDER BY month",
            $baseParams
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

        // Amounts by direction
        $directionAmounts = $conn->fetchAllAssociative(
            'SELECT direction, COALESCE(SUM(total), 0) AS amount FROM invoice WHERE company_id = :companyId AND deleted_at IS NULL' . $dateFilter . ' GROUP BY direction',
            $baseParams
        );
        $amountsByDirection = ['incoming' => '0.00', 'outgoing' => '0.00'];
        foreach ($directionAmounts as $row) {
            if ($row['direction'] === 'incoming') $amountsByDirection['incoming'] = $row['amount'];
            if ($row['direction'] === 'outgoing') $amountsByDirection['outgoing'] = $row['amount'];
        }

        // Recent activity (last 10 synced, filtered by date if provided)
        $qb = $this->invoiceRepository->createQueryBuilder('i')
            ->where('i.company = :company')
            ->setParameter('company', $company)
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

        // Payment summary
        $paymentSummary = $this->paymentService->getPaymentSummary($company, $dateFrom, $dateTo);

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
        ]);

        // Skip cache when date params are present (filtered data)
        if (!$hasDateFilter) {
            $response->setMaxAge(60);
        }
        $response->setPrivate();
        $response->setVary(['X-Company', 'Authorization']);

        return $response;
    }

    private function resolveCompany(Request $request): ?\App\Entity\Company
    {
        return $this->organizationContext->resolveCompany($request);
    }
}
