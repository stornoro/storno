<?php

namespace App\Controller\Api\V1;

use App\Repository\BankAccountRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/cash-register')]
class CashRegisterController extends AbstractController
{
    public function __construct(
        private readonly BankAccountRepository $bankAccountRepository,
        private readonly OrganizationContext $organizationContext,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/balance', methods: ['GET'])]
    public function balance(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::REPORT_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $cashAccount = $this->bankAccountRepository->findCashAccount($company);
        if (!$cashAccount) {
            return $this->json(['configured' => false]);
        }

        if ($cashAccount->getOpeningBalance() === null || $cashAccount->getOpeningBalanceDate() === null) {
            return $this->json([
                'configured' => false,
                'cashAccountId' => (string) $cashAccount->getId(),
                'currency' => $cashAccount->getCurrency(),
            ]);
        }

        $conn = $this->entityManager->getConnection();
        $companyId = (string) $company->getId();
        $openingDate = $cashAccount->getOpeningBalanceDate()->format('Y-m-d');
        $currency = $cashAccount->getCurrency();

        // Cash IN: receipts since opening date (cash payment column for split tendering,
        // fall back to total when paymentMethod = 'cash' and cashPayment column is null).
        // Cancelled/draft receipts are excluded.
        $receiptsSql = "SELECT COALESCE(SUM(
                CASE
                    WHEN cash_payment IS NOT NULL AND cash_payment > 0 THEN cash_payment
                    WHEN payment_method = 'cash' THEN total
                    ELSE 0
                END
            ), 0) AS total
            FROM receipt
            WHERE company_id = :companyId
              AND deleted_at IS NULL
              AND status NOT IN ('draft', 'cancelled')
              AND currency = :currency
              AND issue_date >= :openingDate";

        $cashReceipts = (string) $conn->fetchOne($receiptsSql, [
            'companyId' => $companyId,
            'currency' => $currency,
            'openingDate' => $openingDate,
        ]);

        // Cash OUT: payments paid in cash since opening date.
        $paymentsSql = "SELECT COALESCE(SUM(amount), 0) AS total
            FROM payment p
            INNER JOIN invoice i ON i.id = p.invoice_id
            WHERE i.company_id = :companyId
              AND p.payment_method = 'cash'
              AND p.currency = :currency
              AND p.payment_date >= :openingDate";

        $cashPayments = (string) $conn->fetchOne($paymentsSql, [
            'companyId' => $companyId,
            'currency' => $currency,
            'openingDate' => $openingDate,
        ]);

        $opening = (float) $cashAccount->getOpeningBalance();
        $current = $opening + (float) $cashReceipts - (float) $cashPayments;

        return $this->json([
            'configured' => true,
            'cashAccountId' => (string) $cashAccount->getId(),
            'currency' => $currency,
            'openingBalance' => $cashAccount->getOpeningBalance(),
            'openingBalanceDate' => $openingDate,
            'cashReceipts' => $cashReceipts,
            'cashPayments' => $cashPayments,
            'currentBalance' => number_format($current, 2, '.', ''),
        ]);
    }

    /**
     * Daily ledger report. Returns one bucket per day in [from, to], each with:
     *   opening, entries[] (chronological), closing, totalIn, totalOut.
     * `entries` are derived from cash receipts and cash payments (Phase 2).
     * Manual movements will fold in here in Phase 3.
     */
    #[Route('/ledger', methods: ['GET'])]
    public function ledger(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::REPORT_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $cashAccount = $this->bankAccountRepository->findCashAccount($company);
        if (!$cashAccount || $cashAccount->getOpeningBalance() === null || $cashAccount->getOpeningBalanceDate() === null) {
            return $this->json(['configured' => false, 'days' => []]);
        }

        $opening = $cashAccount->getOpeningBalanceDate();
        $today = new \DateTimeImmutable('today');

        try {
            $from = $request->query->get('from')
                ? new \DateTimeImmutable((string) $request->query->get('from'))
                : $opening;
            $to = $request->query->get('to')
                ? new \DateTimeImmutable((string) $request->query->get('to'))
                : $today;
        } catch (\Exception) {
            return $this->json(['error' => 'Invalid date range.'], Response::HTTP_BAD_REQUEST);
        }

        if ($from < $opening) {
            $from = $opening;
        }
        if ($to < $from) {
            return $this->json(['error' => '`to` must be on or after `from`.'], Response::HTTP_BAD_REQUEST);
        }

        $maxDays = 366;
        if ((int) $from->diff($to)->days > $maxDays) {
            return $this->json(['error' => "Range too large (max {$maxDays} days)."], Response::HTTP_BAD_REQUEST);
        }

        $conn = $this->entityManager->getConnection();
        $companyId = (string) $company->getId();
        $currency = $cashAccount->getCurrency();
        $openingDateStr = $opening->format('Y-m-d');
        $fromStr = $from->format('Y-m-d');
        $toStr   = $to->format('Y-m-d');

        // Cash IN entries from receipts
        $receiptRows = $conn->fetchAllAssociative(
            "SELECT id, number, issue_date AS doc_date, customer_name, payment_method, total, cash_payment, status, issued_at
             FROM receipt
             WHERE company_id = :companyId
               AND deleted_at IS NULL
               AND status NOT IN ('draft', 'cancelled')
               AND currency = :currency
               AND issue_date >= :fromStr
               AND issue_date <= :toStr",
            ['companyId' => $companyId, 'currency' => $currency, 'fromStr' => $fromStr, 'toStr' => $toStr],
        );

        // Cash OUT entries from payments paid in cash
        $paymentRows = $conn->fetchAllAssociative(
            "SELECT p.id, p.amount, p.payment_date AS doc_date, p.reference, p.notes, i.number AS invoice_number, i.id AS invoice_id, i.sender_name
             FROM payment p
             INNER JOIN invoice i ON i.id = p.invoice_id
             WHERE i.company_id = :companyId
               AND p.payment_method = 'cash'
               AND p.currency = :currency
               AND p.payment_date >= :fromStr
               AND p.payment_date <= :toStr",
            ['companyId' => $companyId, 'currency' => $currency, 'fromStr' => $fromStr, 'toStr' => $toStr],
        );

        // Pre-range running balance: opening + receipts/payments between openingDate and (from-1)
        $running = (float) $cashAccount->getOpeningBalance();
        if ($fromStr > $openingDateStr) {
            $preIn = (float) $conn->fetchOne(
                "SELECT COALESCE(SUM(
                    CASE
                        WHEN cash_payment IS NOT NULL AND cash_payment > 0 THEN cash_payment
                        WHEN payment_method = 'cash' THEN total
                        ELSE 0
                    END
                ), 0)
                FROM receipt
                WHERE company_id = :companyId
                  AND deleted_at IS NULL
                  AND status NOT IN ('draft', 'cancelled')
                  AND currency = :currency
                  AND issue_date >= :openingDate
                  AND issue_date < :fromStr",
                ['companyId' => $companyId, 'currency' => $currency, 'openingDate' => $openingDateStr, 'fromStr' => $fromStr],
            );
            $preOut = (float) $conn->fetchOne(
                "SELECT COALESCE(SUM(p.amount), 0)
                FROM payment p
                INNER JOIN invoice i ON i.id = p.invoice_id
                WHERE i.company_id = :companyId
                  AND p.payment_method = 'cash'
                  AND p.currency = :currency
                  AND p.payment_date >= :openingDate
                  AND p.payment_date < :fromStr",
                ['companyId' => $companyId, 'currency' => $currency, 'openingDate' => $openingDateStr, 'fromStr' => $fromStr],
            );
            $running += $preIn - $preOut;
        }

        // Bucket entries by day
        $buckets = [];
        $cursor = $from;
        while ($cursor <= $to) {
            $key = $cursor->format('Y-m-d');
            $buckets[$key] = ['date' => $key, 'entries' => []];
            $cursor = $cursor->modify('+1 day');
        }

        foreach ($receiptRows as $r) {
            $key = substr((string) $r['doc_date'], 0, 10);
            if (!isset($buckets[$key])) continue;
            $cashAmount = $r['cash_payment'] !== null && (float) $r['cash_payment'] > 0
                ? (string) $r['cash_payment']
                : ($r['payment_method'] === 'cash' ? (string) $r['total'] : '0.00');
            if ((float) $cashAmount <= 0) continue;
            $buckets[$key]['entries'][] = [
                'kind' => 'receipt',
                'documentNumber' => $r['number'],
                'documentType' => 'chitanta',
                'description' => $r['customer_name'] ?: '-',
                'in' => $cashAmount,
                'out' => '0.00',
                'sourceId' => bin2hex((string) $r['id']),
                'sortKey' => (string) ($r['issued_at'] ?? $r['doc_date']),
            ];
        }

        foreach ($paymentRows as $p) {
            $key = substr((string) $p['doc_date'], 0, 10);
            if (!isset($buckets[$key])) continue;
            $buckets[$key]['entries'][] = [
                'kind' => 'payment',
                'documentNumber' => $p['invoice_number'] ?: $p['reference'] ?: '-',
                'documentType' => 'plata',
                'description' => $p['sender_name'] ?: ($p['notes'] ?: '-'),
                'in' => '0.00',
                'out' => (string) $p['amount'],
                'sourceId' => bin2hex((string) $p['id']),
                'sortKey' => (string) $p['doc_date'],
            ];
        }

        // Sort entries within each day, compute running balance.
        $days = [];
        foreach ($buckets as $key => $bucket) {
            usort($bucket['entries'], fn($a, $b) => strcmp($a['sortKey'], $b['sortKey']));
            $opening = $running;
            $totalIn = 0.0;
            $totalOut = 0.0;
            foreach ($bucket['entries'] as &$entry) {
                $running += (float) $entry['in'] - (float) $entry['out'];
                $entry['balanceAfter'] = number_format($running, 2, '.', '');
                $totalIn += (float) $entry['in'];
                $totalOut += (float) $entry['out'];
                unset($entry['sortKey']);
            }
            unset($entry);

            $days[] = [
                'date' => $key,
                'opening' => number_format($opening, 2, '.', ''),
                'totalIn' => number_format($totalIn, 2, '.', ''),
                'totalOut' => number_format($totalOut, 2, '.', ''),
                'closing' => number_format($running, 2, '.', ''),
                'entries' => $bucket['entries'],
            ];
        }

        return $this->json([
            'configured' => true,
            'currency' => $currency,
            'openingBalanceDate' => $openingDateStr,
            'from' => $fromStr,
            'to' => $toStr,
            'days' => $days,
        ]);
    }
}
