<?php

namespace App\Controller\Api\V1;

use App\Entity\BankAccount;
use App\Repository\BankAccountRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1')]
class BankAccountController extends AbstractController
{
    public function __construct(
        private readonly BankAccountRepository $bankAccountRepository,
        private readonly OrganizationContext $organizationContext,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/bank-accounts', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::SETTINGS_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $accounts = $this->bankAccountRepository->findByCompany($company);

        return $this->json(['data' => $accounts], context: ['groups' => ['bankaccount:list']]);
    }

    #[Route('/bank-accounts', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::SETTINGS_MANAGE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $type = $data['type'] ?? BankAccount::TYPE_BANK;

        if (!in_array($type, BankAccount::TYPES, true)) {
            return $this->json(['error' => 'Invalid type. Allowed: ' . implode(', ', BankAccount::TYPES)], Response::HTTP_BAD_REQUEST);
        }

        if ($type === BankAccount::TYPE_CASH) {
            if ($this->bankAccountRepository->findCashAccount($company)) {
                return $this->json(['error' => 'A cash account already exists for this company.'], Response::HTTP_CONFLICT);
            }
        } else {
            $iban = $data['iban'] ?? null;
            if (!$iban) {
                return $this->json(['error' => 'Field "iban" is required.'], Response::HTTP_BAD_REQUEST);
            }
            if ($this->bankAccountRepository->findByIban($company, $iban)) {
                return $this->json(['error' => 'Bank account with this IBAN already exists.'], Response::HTTP_CONFLICT);
            }
        }

        $account = new BankAccount();
        $account->setCompany($company);
        $account->setType($type);
        $account->setIban($type === BankAccount::TYPE_BANK ? ($data['iban'] ?? null) : null);
        $account->setBankName($data['bankName'] ?? null);
        $account->setCurrency($data['currency'] ?? 'RON');
        $account->setIsDefault($type === BankAccount::TYPE_BANK && ($data['isDefault'] ?? false));
        $account->setShowOnInvoice($type === BankAccount::TYPE_BANK && ($data['showOnInvoice'] ?? false));

        if ($type === BankAccount::TYPE_CASH) {
            $error = $this->applyOpeningBalance($account, $data, false);
            if ($error) {
                return $error;
            }
        }

        // If setting as default, unset others
        if ($account->isDefault()) {
            $this->unsetOtherDefaults($company);
        }

        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $this->json($account, Response::HTTP_CREATED, context: ['groups' => ['bankaccount:detail']]);
    }

    #[Route('/bank-accounts/{uuid}', methods: ['PATCH'])]
    public function update(string $uuid, Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        $account = $this->bankAccountRepository->find($uuid);
        if (!$account || $account->getCompany()?->getId()->toRfc4122() !== $company->getId()->toRfc4122()) {
            return $this->json(['error' => 'Bank account not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::SETTINGS_MANAGE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['bankName'])) {
            $account->setBankName($data['bankName']);
        }
        if (isset($data['currency'])) {
            $account->setCurrency($data['currency']);
        }
        if (isset($data['isDefault'])) {
            $account->setIsDefault((bool) $data['isDefault']);
            if ($account->isDefault()) {
                $this->unsetOtherDefaults($account->getCompany(), $account);
            }
        }
        if (isset($data['showOnInvoice'])) {
            $account->setShowOnInvoice($account->isCash() ? false : (bool) $data['showOnInvoice']);
        }

        if ($account->isCash() && (array_key_exists('openingBalance', $data) || array_key_exists('openingBalanceDate', $data))) {
            $error = $this->applyOpeningBalance($account, $data, true);
            if ($error) {
                return $error;
            }
        }

        $this->entityManager->flush();

        return $this->json($account, context: ['groups' => ['bankaccount:detail']]);
    }

    #[Route('/bank-accounts/{uuid}', methods: ['DELETE'])]
    public function delete(string $uuid, Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        $account = $this->bankAccountRepository->find($uuid);
        if (!$account || $account->getCompany()?->getId()->toRfc4122() !== $company->getId()->toRfc4122()) {
            return $this->json(['error' => 'Bank account not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::SETTINGS_MANAGE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $this->entityManager->remove($account);
        $this->entityManager->flush();

        return $this->json(['message' => 'Bank account deleted.']);
    }

    /**
     * Validate and apply openingBalance / openingBalanceDate fields.
     * Opening balance must be ≥ 0. The user can keep editing it freely until
     * cash transactions (receipts, payments, or manual movements) exist for
     * this account; after that, the change requires `confirmReset: true` and
     * the current balance simply re-derives against the new opening point.
     * Returns a JsonResponse on validation error, null on success.
     */
    private function applyOpeningBalance(BankAccount $account, array $data, bool $isUpdate): ?JsonResponse
    {
        $confirmReset = !empty($data['confirmReset']);
        if ($isUpdate && $account->getOpeningBalance() !== null && !$confirmReset) {
            if ($this->cashAccountHasTransactions($account)) {
                return $this->json([
                    'error' => 'opening_balance_locked',
                    'message' => 'Opening balance is locked because cash transactions exist for this account. Pass confirmReset=true to override; the current balance will be recomputed against the new opening point.',
                ], Response::HTTP_CONFLICT);
            }
        }

        $rawAmount = $data['openingBalance'] ?? null;
        $rawDate   = $data['openingBalanceDate'] ?? null;

        if ($rawAmount === null && $rawDate === null) {
            return null; // nothing to apply, allow create with unconfigured balance
        }

        if ($rawAmount === null || $rawDate === null) {
            return $this->json(['error' => 'openingBalance and openingBalanceDate must both be provided.'], Response::HTTP_BAD_REQUEST);
        }

        if (!is_numeric($rawAmount) || (float) $rawAmount < 0) {
            return $this->json(['error' => 'openingBalance must be zero or a positive number.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $date = new \DateTimeImmutable((string) $rawDate);
        } catch (\Exception) {
            return $this->json(['error' => 'openingBalanceDate is not a valid date (YYYY-MM-DD expected).'], Response::HTTP_BAD_REQUEST);
        }

        $account->setOpeningBalance(number_format((float) $rawAmount, 2, '.', ''));
        $account->setOpeningBalanceDate($date);

        return null;
    }

    /**
     * Cash transactions = cash receipts (payment_method=cash or split-cash > 0)
     * + payments paid in cash + manual cash movements, scoped to this account's
     * company and currency.
     */
    private function cashAccountHasTransactions(BankAccount $account): bool
    {
        $company = $account->getCompany();
        if (!$company) {
            return false;
        }
        $companyId = (string) $company->getId();
        $currency = $account->getCurrency();
        $conn = $this->entityManager->getConnection();

        $hasReceipt = (bool) $conn->fetchOne(
            "SELECT 1 FROM receipt
             WHERE company_id = :c
               AND deleted_at IS NULL
               AND status NOT IN ('draft', 'cancelled')
               AND currency = :cur
               AND (payment_method = 'cash' OR (cash_payment IS NOT NULL AND cash_payment > 0))
             LIMIT 1",
            ['c' => $companyId, 'cur' => $currency],
        );
        if ($hasReceipt) {
            return true;
        }

        $hasPayment = (bool) $conn->fetchOne(
            "SELECT 1 FROM payment p
             INNER JOIN invoice i ON i.id = p.invoice_id
             WHERE i.company_id = :c
               AND p.payment_method = 'cash'
               AND p.currency = :cur
             LIMIT 1",
            ['c' => $companyId, 'cur' => $currency],
        );
        if ($hasPayment) {
            return true;
        }

        return (bool) $conn->fetchOne(
            'SELECT 1 FROM cash_movement WHERE company_id = :c AND currency = :cur LIMIT 1',
            ['c' => $companyId, 'cur' => $currency],
        );
    }

    private function unsetOtherDefaults(\App\Entity\Company $company, ?BankAccount $except = null): void
    {
        $accounts = $this->bankAccountRepository->findByCompany($company);
        foreach ($accounts as $account) {
            if ($except && $account->getId()?->equals($except->getId())) {
                continue;
            }
            if ($account->isDefault()) {
                $account->setIsDefault(false);
            }
        }
    }
}
