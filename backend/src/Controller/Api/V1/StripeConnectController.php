<?php

namespace App\Controller\Api\V1;

use App\Repository\PaymentRepository;
use App\Repository\StripeConnectAccountRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Service\StripeConnectService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/stripe-connect')]
class StripeConnectController extends AbstractController
{
    public function __construct(
        private readonly StripeConnectService $connectService,
        private readonly StripeConnectAccountRepository $connectAccountRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly OrganizationContext $organizationContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Create Connect account + return onboarding URL.
     */
    #[Route('/onboard', name: 'stripe_connect_onboard', methods: ['POST'])]
    public function onboard(): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany();
        if (!$company) {
            return $this->json(['error' => 'Company not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::ORG_MANAGE_BILLING)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        try {
            $url = $this->connectService->createOnboardingLink($company);

            return $this->json(['url' => $url]);
        } catch (\Exception $e) {
            $this->logger->error('Connect onboarding failed', ['error' => $e->getMessage()]);

            return $this->json(['error' => 'Failed to create onboarding link'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get Connect account status.
     */
    #[Route('/status', name: 'stripe_connect_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany();
        if (!$company) {
            return $this->json(['error' => 'Company not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::ORG_MANAGE_BILLING)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $connectAccount = $this->connectAccountRepository->findByCompany($company);
        if (!$connectAccount) {
            return $this->json([
                'connected' => false,
            ]);
        }

        // Sync latest status from Stripe
        try {
            $this->connectService->syncAccountStatus($connectAccount);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to sync connect status', ['error' => $e->getMessage()]);
        }

        return $this->json([
            'connected' => true,
            'stripeAccountId' => $connectAccount->getStripeAccountId(),
            'chargesEnabled' => $connectAccount->isChargesEnabled(),
            'payoutsEnabled' => $connectAccount->isPayoutsEnabled(),
            'detailsSubmitted' => $connectAccount->isDetailsSubmitted(),
            'onboardingComplete' => $connectAccount->isOnboardingComplete(),
            'settings' => [
                'paymentEnabledByDefault' => $connectAccount->isPaymentEnabledByDefault(),
                'allowPartialPayments' => $connectAccount->isAllowPartialPayments(),
                'successMessage' => $connectAccount->getSuccessMessage(),
                'notifyOnPayment' => $connectAccount->isNotifyOnPayment(),
            ],
        ]);
    }

    /**
     * Get dashboard login link.
     */
    #[Route('/dashboard', name: 'stripe_connect_dashboard', methods: ['POST'])]
    public function dashboard(): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany();
        if (!$company) {
            return $this->json(['error' => 'Company not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::ORG_MANAGE_BILLING)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        try {
            $url = $this->connectService->createDashboardLink($company);

            return $this->json(['url' => $url]);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('Dashboard link failed', ['error' => $e->getMessage()]);

            return $this->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get Stripe payment stats for the current company.
     */
    #[Route('/stats', name: 'stripe_connect_stats', methods: ['GET'])]
    public function stats(): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany();
        if (!$company) {
            return $this->json(['error' => 'Company not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::ORG_MANAGE_BILLING)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $totalAmount = $this->paymentRepository->sumStripePaymentsByCompany($company);
        $totalCount = $this->paymentRepository->countStripePaymentsByCompany($company);
        $recentPayments = $this->paymentRepository->findStripePaymentsByCompany($company);

        return $this->json([
            'totalAmount' => $totalAmount,
            'totalCount' => $totalCount,
            'recentPayments' => array_map(fn ($p) => [
                'id' => (string) $p->getId(),
                'amount' => $p->getAmount(),
                'currency' => $p->getCurrency(),
                'reference' => $p->getReference(),
                'paymentDate' => $p->getPaymentDate()?->format('Y-m-d'),
                'createdAt' => $p->getPaymentCreatedAt()?->format('c'),
            ], $recentPayments),
        ]);
    }

    /**
     * Update payment settings.
     */
    #[Route('/settings', name: 'stripe_connect_settings', methods: ['PATCH'])]
    public function settings(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany();
        if (!$company) {
            return $this->json(['error' => 'Company not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::ORG_MANAGE_BILLING)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $connectAccount = $this->connectAccountRepository->findByCompany($company);
        if (!$connectAccount) {
            return $this->json(['error' => 'Stripe Connect account not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('paymentEnabledByDefault', $data)) {
            $connectAccount->setPaymentEnabledByDefault((bool) $data['paymentEnabledByDefault']);
        }
        if (array_key_exists('allowPartialPayments', $data)) {
            $connectAccount->setAllowPartialPayments((bool) $data['allowPartialPayments']);
        }
        if (array_key_exists('successMessage', $data)) {
            $value = $data['successMessage'];
            $connectAccount->setSuccessMessage(is_string($value) && trim($value) !== '' ? trim($value) : null);
        }
        if (array_key_exists('notifyOnPayment', $data)) {
            $connectAccount->setNotifyOnPayment((bool) $data['notifyOnPayment']);
        }

        $this->entityManager->flush();

        return $this->json([
            'paymentEnabledByDefault' => $connectAccount->isPaymentEnabledByDefault(),
            'allowPartialPayments' => $connectAccount->isAllowPartialPayments(),
            'successMessage' => $connectAccount->getSuccessMessage(),
            'notifyOnPayment' => $connectAccount->isNotifyOnPayment(),
        ]);
    }

    /**
     * Disconnect Stripe Connect.
     */
    #[Route('', name: 'stripe_connect_disconnect', methods: ['DELETE'])]
    public function disconnect(): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany();
        if (!$company) {
            return $this->json(['error' => 'Company not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::ORG_MANAGE_BILLING)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        try {
            $this->connectService->disconnect($company);

            return $this->json(['status' => 'disconnected']);
        } catch (\Exception $e) {
            $this->logger->error('Disconnect failed', ['error' => $e->getMessage()]);

            return $this->json(['error' => 'Failed to disconnect'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
