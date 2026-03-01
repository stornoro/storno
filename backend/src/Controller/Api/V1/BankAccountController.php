<?php

namespace App\Controller\Api\V1;

use App\Entity\BankAccount;
use App\Repository\BankAccountRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Service\LicenseManager;
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
        private readonly LicenseManager $licenseManager,
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

        $org = $company->getOrganization();
        if (!$this->licenseManager->canUseBankStatements($org)) {
            return $this->json([
                'error' => 'Bank accounts are not available on your plan.',
                'code' => 'PLAN_LIMIT',
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        $data = json_decode($request->getContent(), true);
        $iban = $data['iban'] ?? null;

        if (!$iban) {
            return $this->json(['error' => 'Field "iban" is required.'], Response::HTTP_BAD_REQUEST);
        }

        $existing = $this->bankAccountRepository->findByIban($company, $iban);
        if ($existing) {
            return $this->json(['error' => 'Bank account with this IBAN already exists.'], Response::HTTP_CONFLICT);
        }

        $account = new BankAccount();
        $account->setCompany($company);
        $account->setIban($iban);
        $account->setBankName($data['bankName'] ?? null);
        $account->setCurrency($data['currency'] ?? 'RON');
        $account->setIsDefault($data['isDefault'] ?? false);
        $account->setShowOnInvoice($data['showOnInvoice'] ?? false);

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
            $account->setShowOnInvoice((bool) $data['showOnInvoice']);
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
