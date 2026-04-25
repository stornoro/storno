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
}
