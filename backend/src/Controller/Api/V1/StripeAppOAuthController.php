<?php

namespace App\Controller\Api\V1;

use App\Entity\StripeAppDeviceCode;
use App\Enum\MessageKey;
use App\Entity\StripeAppToken;
use App\Enum\DocumentStatus;
use App\Manager\InvoiceManager;
use App\Repository\CompanyRepository;
use App\Repository\InvoiceRepository;
use App\Repository\OrganizationMembershipRepository;
use App\Repository\StripeAppDeviceCodeRepository;
use App\Repository\StripeAppTokenRepository;
use App\Service\StripeAppInvoiceService;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/stripe-app')]
class StripeAppOAuthController extends AbstractController
{
    public function __construct(
        private readonly StripeAppTokenRepository $tokenRepository,
        private readonly StripeAppDeviceCodeRepository $deviceCodeRepository,
        private readonly CompanyRepository $companyRepository,
        private readonly OrganizationMembershipRepository $membershipRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly EntityManagerInterface $em,
        private readonly JWTEncoderInterface $jwtEncoder,
        private readonly UserProviderInterface $userProvider,
        private readonly InvoiceManager $invoiceManager,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/token', name: 'stripe_app_token', methods: ['POST'])]
    public function token(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $grantType = $data['grant_type'] ?? null;

        return match ($grantType) {
            'authorization_code' => $this->handleAuthorizationCode($data),
            'refresh_token' => $this->handleRefreshToken($data),
            'device_code', 'urn:ietf:params:oauth:grant-type:device_code' => $this->handleDeviceCode($data),
            default => $this->json(['error' => 'unsupported_grant_type', 'message' => 'Tip de autentificare nesuportat'], Response::HTTP_BAD_REQUEST),
        };
    }

    #[Route('/disconnect', name: 'stripe_app_disconnect', methods: ['POST'])]
    public function disconnect(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $stripeAccountId = $data['stripe_account_id'] ?? null;

        if (!$stripeAccountId) {
            return $this->json(['error' => 'invalid_request', 'message' => 'stripe_account_id is required'], Response::HTTP_BAD_REQUEST);
        }

        $appToken = $this->tokenRepository->findByStripeAccountId($stripeAccountId);

        if ($appToken) {
            $this->em->remove($appToken);
            $this->em->flush();
        }

        return $this->json(['status' => 'disconnected']);
    }

    /**
     * RFC 8628 step 1: device authorization request. The Stripe app calls this
     * to obtain a device_code (long, opaque, polled by the app) and a
     * user_code (short, displayed to the user, entered on the verification UI).
     */
    #[Route('/oauth/device', name: 'stripe_app_oauth_device', methods: ['POST'])]
    public function oauthDevice(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $stripeAccountId = $data['stripe_account_id'] ?? null;

        if (!$stripeAccountId) {
            return $this->json(['error' => 'invalid_request', 'message' => 'stripe_account_id este obligatoriu'], Response::HTTP_BAD_REQUEST);
        }

        // Retry on the (extremely unlikely) user_code collision
        $deviceCode = null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = StripeAppDeviceCode::generateUserCode();
            if (!$this->deviceCodeRepository->findByUserCode($candidate)) {
                $deviceCode = new StripeAppDeviceCode();
                $deviceCode->setDeviceCode(StripeAppDeviceCode::generateDeviceCode());
                $deviceCode->setUserCode($candidate);
                $deviceCode->setStripeAccountId($stripeAccountId);
                $deviceCode->setExpiresAt(new \DateTimeImmutable('+10 minutes'));
                break;
            }
        }

        if (!$deviceCode) {
            return $this->json(['error' => 'server_error', 'message' => 'Nu s-a putut genera codul'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->em->persist($deviceCode);
        $this->em->flush();

        $verificationBase = 'https://app.storno.ro/stripe-link';

        return $this->json([
            'device_code' => $deviceCode->getDeviceCode(),
            'user_code' => $deviceCode->getUserCode(),
            'verification_uri' => $verificationBase,
            'verification_uri_complete' => $verificationBase.'?code='.$deviceCode->getUserCode(),
            'expires_in' => 600,
            'interval' => 2,
        ]);
    }

    /**
     * RFC 8628 step 3: verification UI calls this to approve a pending
     * device authorization. The consenting user must supply the company_id
     * they are granting access to; the server validates that they actually
     * have access to that company via their OrganizationMembership (respecting
     * allowedCompanies restrictions). Both user and company are stored on the
     * device code and carried to the resulting StripeAppToken.
     */
    #[Route('/oauth/approve', name: 'stripe_app_oauth_approve', methods: ['POST'])]
    public function oauthApprove(Request $request): JsonResponse
    {
        $user = $this->getUser();

        if (!$user) {
            return $this->json(['error' => 'unauthorized', 'message' => 'Sesiunea a expirat. Reconecteaza-te.', 'messageKey' => MessageKey::ERR_SESSION_EXPIRED], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $userCode = strtoupper(trim((string) ($data['user_code'] ?? '')));
        $approve = (bool) ($data['approve'] ?? true);
        $companyId = $data['company_id'] ?? null;

        if ($userCode === '') {
            return $this->json(['error' => 'invalid_request', 'message' => 'Codul este obligatoriu'], Response::HTTP_BAD_REQUEST);
        }

        if ($approve && !$companyId) {
            return $this->json(['error' => 'invalid_request', 'message' => 'company_id este obligatoriu'], Response::HTTP_BAD_REQUEST);
        }

        $deviceCode = $this->deviceCodeRepository->findByUserCode($userCode);

        if (!$deviceCode) {
            return $this->json(['error' => 'invalid_grant', 'message' => 'Cod invalid'], Response::HTTP_NOT_FOUND);
        }

        if ($deviceCode->isExpired()) {
            return $this->json(['error' => 'expired_token', 'message' => 'Codul a expirat'], Response::HTTP_GONE);
        }

        if (!$deviceCode->isPending()) {
            return $this->json(['error' => 'invalid_grant', 'message' => 'Codul a fost deja folosit'], Response::HTTP_CONFLICT);
        }

        if ($approve) {
            try {
                $companyUuid = Uuid::fromString($companyId);
            } catch (\Throwable) {
                return $this->json(['error' => 'invalid_request', 'message' => 'company_id invalid'], Response::HTTP_BAD_REQUEST);
            }

            $company = $this->companyRepository->find($companyUuid);
            if (!$company) {
                return $this->json(['error' => 'not_found', 'message' => 'Compania nu a fost gasita'], Response::HTTP_NOT_FOUND);
            }

            // Verify the user has access to this company via an active membership,
            // honouring per-membership allowedCompanies restrictions.
            $organization = $company->getOrganization();
            $membership = $this->membershipRepository->findByUserAndOrganization($user, $organization);

            if (!$membership || !$membership->hasAccessToCompany($company)) {
                return $this->json(['error' => 'forbidden', 'message' => 'no access to this company'], Response::HTTP_FORBIDDEN);
            }

            $deviceCode->setStatus(StripeAppDeviceCode::STATUS_APPROVED);
            $deviceCode->setUser($user);
            $deviceCode->setCompany($company);
            $deviceCode->setOrganization($organization);
            $deviceCode->setApprovedAt(new \DateTimeImmutable());
        } else {
            $deviceCode->setStatus(StripeAppDeviceCode::STATUS_DENIED);
        }

        $this->em->flush();

        return $this->json([
            'status' => $approve ? 'approved' : 'denied',
            'stripe_account_id' => $deviceCode->getStripeAccountId(),
        ]);
    }

    #[Route('/settings', name: 'stripe_app_settings_get', methods: ['GET'])]
    public function getSettings(Request $request): JsonResponse
    {
        $appToken = $this->resolveAppToken($request);

        if (!$appToken) {
            return $this->json(['error' => 'unauthorized', 'message' => 'Session expired. Please reconnect from Settings.', 'messageKey' => MessageKey::ERR_SESSION_EXPIRED], Response::HTTP_UNAUTHORIZED);
        }

        $company = $appToken->getCompany();

        $user = $appToken->getUser();

        return $this->json([
            'autoMode' => $appToken->isAutoMode(),
            'company' => [
                'id' => $company->getId()->toRfc4122(),
                'name' => $company->getName(),
                'cif' => $company->getCif(),
            ],
            'locale' => $user?->getLocale(),
            'connectedUser' => $user ? [
                'email' => $user->getEmail(),
                'name' => $user->getFullName(),
                'connectedAt' => $appToken->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ] : null,
        ]);
    }

    #[Route('/settings', name: 'stripe_app_settings_update', methods: ['PUT'])]
    public function updateSettings(Request $request): JsonResponse
    {
        $appToken = $this->resolveAppToken($request);

        if (!$appToken) {
            return $this->json(['error' => 'unauthorized', 'message' => 'Session expired. Please reconnect from Settings.', 'messageKey' => MessageKey::ERR_SESSION_EXPIRED], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['autoMode'])) {
            $appToken->setAutoMode((bool) $data['autoMode']);
        }

        $appToken->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $this->json([
            'autoMode' => $appToken->isAutoMode(),
            'company' => [
                'id' => $appToken->getCompany()->getId()->toRfc4122(),
                'name' => $appToken->getCompany()->getName(),
                'cif' => $appToken->getCompany()->getCif(),
            ],
        ]);
    }

    #[Route('/dashboard', name: 'stripe_app_dashboard', methods: ['GET'])]
    public function dashboard(Request $request): JsonResponse
    {
        $appToken = $this->resolveAppToken($request);

        if (!$appToken) {
            return $this->json(['error' => 'unauthorized', 'message' => 'Session expired. Please reconnect from Settings.', 'messageKey' => MessageKey::ERR_SESSION_EXPIRED], Response::HTTP_UNAUTHORIZED);
        }

        $company = $appToken->getCompany();

        $counts = $this->invoiceRepository->createQueryBuilder('i')
            ->select('i.status, COUNT(i.id) as cnt')
            ->where('i.company = :company')
            ->andWhere('i.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->groupBy('i.status')
            ->getQuery()
            ->getResult();

        // sent_to_anaf is the display name used by the extension; the internal
        // enum value is sent_to_provider (generic across e-invoice providers).
        $statusCounts = [
            'draft' => 0,
            'issued' => 0,
            'sent_to_anaf' => 0,
            'validated' => 0,
            'rejected' => 0,
            'total' => 0,
        ];

        foreach ($counts as $row) {
            $status = $row['status'] instanceof DocumentStatus ? $row['status']->value : $row['status'];
            // Map internal sent_to_provider → sent_to_anaf for the extension API.
            $key = $status === 'sent_to_provider' ? 'sent_to_anaf' : $status;
            if (isset($statusCounts[$key])) {
                $statusCounts[$key] = (int) $row['cnt'];
            }
            $statusCounts['total'] += (int) $row['cnt'];
        }

        $recentInvoices = $this->invoiceRepository->createQueryBuilder('i')
            ->where('i.company = :company')
            ->andWhere('i.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->orderBy('i.createdAt', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        $invoicesData = array_map(function ($inv) {
            $status = $inv->getStatus()->value;
            // Normalise sent_to_provider → sent_to_anaf for the extension API.
            if ($status === 'sent_to_provider') {
                $status = 'sent_to_anaf';
            }

            return [
                'id' => $inv->getId()->toRfc4122(),
                'invoiceNumber' => $inv->getNumber(),
                'issueDate' => $inv->getIssueDate()?->format('Y-m-d'),
                'total' => $inv->getTotal(),
                'currency' => $inv->getCurrency(),
                'receiverName' => $inv->getReceiverName(),
                'status' => $status,
                'anafStatus' => $inv->getAnafStatus(),
                'anafErrorMessage' => $inv->getAnafErrorMessage(),
            ];
        }, $recentInvoices);

        return $this->json([
            'counts' => $statusCounts,
            'recentInvoices' => $invoicesData,
            'autoMode' => $appToken->isAutoMode(),
            'companyName' => $company->getName(),
        ]);
    }

    #[Route('/invoices/create-from-stripe', name: 'stripe_app_create_from_stripe', methods: ['POST'])]
    public function createFromStripe(Request $request, StripeAppInvoiceService $invoiceService): JsonResponse
    {
        $appToken = $this->resolveAppToken($request);

        if (!$appToken) {
            return $this->json(['error' => 'unauthorized', 'message' => 'Session expired. Please reconnect from Settings.', 'messageKey' => MessageKey::ERR_SESSION_EXPIRED], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $stripeInvoiceId = $data['stripeInvoiceId'] ?? null;

        if (!$stripeInvoiceId) {
            return $this->json(['error' => 'invalid_request', 'message' => 'stripeInvoiceId is required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $invoice = $invoiceService->createFromStripeInvoiceId($appToken, $stripeInvoiceId);

            return $this->json([
                'id' => $invoice->getId()->toRfc4122(),
                'invoiceNumber' => $invoice->getNumber(),
                'status' => $invoice->getStatus()->value,
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            $this->logger->error('Stripe App: create from stripe failed', [
                'stripeInvoiceId' => $stripeInvoiceId,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'error' => 'creation_failed',
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    #[Route('/invoices/{uuid}/retry', name: 'stripe_app_invoice_retry', methods: ['POST'])]
    public function retryInvoice(string $uuid, Request $request): JsonResponse
    {
        $appToken = $this->resolveAppToken($request);

        if (!$appToken) {
            return $this->json(['error' => 'unauthorized', 'message' => 'Session expired. Please reconnect from Settings.', 'messageKey' => MessageKey::ERR_SESSION_EXPIRED], Response::HTTP_UNAUTHORIZED);
        }

        $invoice = $this->invoiceRepository->find(Uuid::fromString($uuid));

        if (!$invoice) {
            return $this->json(['error' => 'not_found', 'message' => 'Invoice not found.', 'messageKey' => MessageKey::ERR_INVOICE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if ($invoice->getCompany() !== $appToken->getCompany()) {
            return $this->json(['error' => 'forbidden', 'message' => 'You do not have permission for this invoice.', 'messageKey' => MessageKey::ERR_NO_PERMISSION], Response::HTTP_FORBIDDEN);
        }

        try {
            $this->invoiceManager->submitToAnaf($invoice, $appToken->getUser());

            return $this->json([
                'id' => $invoice->getId()->toRfc4122(),
                'status' => $invoice->getStatus()->value,
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'retry_failed',
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function handleAuthorizationCode(array $data): JsonResponse
    {
        $code = $data['code'] ?? null;
        $stripeAccountId = $data['stripe_account_id'] ?? null;

        if (!$code || !$stripeAccountId) {
            return $this->json(['error' => 'invalid_request', 'message' => 'Parametri lipsa: code si stripe_account_id sunt obligatorii.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $payload = $this->jwtEncoder->decode($code);

            if (!$payload || empty($payload['username'])) {
                return $this->json(['error' => 'invalid_grant', 'message' => 'Token JWT invalid.'], Response::HTTP_UNAUTHORIZED);
            }

            $user = $this->userProvider->loadUserByIdentifier($payload['username']);
        } catch (\Exception $e) {
            $this->logger->warning('Stripe App OAuth: invalid code', ['error' => $e->getMessage()]);

            return $this->json(['error' => 'invalid_grant', 'message' => 'Token de autorizare invalid sau expirat.'], Response::HTTP_UNAUTHORIZED);
        }

        // authorization_code flow does not carry a company — unsupported without device flow
        return $this->json(['error' => 'unsupported_grant_type', 'message' => 'Foloseste fluxul device_code pentru conectare.'], Response::HTTP_BAD_REQUEST);
    }

    private function handleRefreshToken(array $data): JsonResponse
    {
        $refreshToken = $data['refresh_token'] ?? null;

        if (!$refreshToken) {
            return $this->json(['error' => 'invalid_request', 'message' => 'Refresh token lipsa.'], Response::HTTP_BAD_REQUEST);
        }

        $appToken = $this->tokenRepository->findOneBy(['refreshToken' => $refreshToken]);

        if (!$appToken) {
            return $this->json(['error' => 'invalid_grant', 'message' => 'Sesiunea a expirat. Reconecteaza-te din Setari.'], Response::HTTP_UNAUTHORIZED);
        }

        $newAccessToken = bin2hex(random_bytes(32));
        $newRefreshToken = bin2hex(random_bytes(32));

        $appToken->setAccessToken($newAccessToken);
        $appToken->setRefreshToken($newRefreshToken);
        $appToken->setExpiresAt(new \DateTimeImmutable('+1 hour'));
        $appToken->setUpdatedAt(new \DateTimeImmutable());

        $this->em->flush();

        return $this->json([
            'access_token' => $newAccessToken,
            'refresh_token' => $newRefreshToken,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]);
    }

    /**
     * RFC 8628 step 4: app polls /token with the device_code. Returns
     * tokens once approved, or `authorization_pending` / `slow_down` /
     * `expired_token` / `access_denied` per RFC.
     */
    private function handleDeviceCode(array $data): JsonResponse
    {
        $code = (string) ($data['device_code'] ?? '');
        $stripeAccountId = $data['stripe_account_id'] ?? null;

        if ($code === '' || !$stripeAccountId) {
            return $this->json(['error' => 'invalid_request', 'message' => 'device_code si stripe_account_id sunt obligatorii'], Response::HTTP_BAD_REQUEST);
        }

        $deviceCode = $this->deviceCodeRepository->findByDeviceCode($code);

        if (!$deviceCode || $deviceCode->getStripeAccountId() !== $stripeAccountId) {
            return $this->json(['error' => 'invalid_grant', 'message' => 'Cod invalid'], Response::HTTP_BAD_REQUEST);
        }

        // Slow-down enforcement: minimum 1s between polls
        $now = new \DateTimeImmutable();
        $last = $deviceCode->getLastPolledAt();
        if ($last && ($now->getTimestamp() - $last->getTimestamp()) < 1) {
            $deviceCode->setLastPolledAt($now);
            $this->em->flush();

            return $this->json(['error' => 'slow_down', 'message' => 'Poll prea des'], Response::HTTP_BAD_REQUEST);
        }
        $deviceCode->setLastPolledAt($now);

        if ($deviceCode->isExpired()) {
            $this->em->flush();

            return $this->json(['error' => 'expired_token', 'message' => 'Codul a expirat'], Response::HTTP_BAD_REQUEST);
        }

        if ($deviceCode->isDenied()) {
            $this->em->flush();

            return $this->json(['error' => 'access_denied', 'message' => 'Autorizarea a fost refuzata'], Response::HTTP_BAD_REQUEST);
        }

        if ($deviceCode->isPending()) {
            $this->em->flush();

            return $this->json(['error' => 'authorization_pending', 'message' => 'In asteptarea autorizarii'], Response::HTTP_BAD_REQUEST);
        }

        // Approved — the company was captured at approve time
        $user = $deviceCode->getUser();
        $company = $deviceCode->getCompany();

        if (!$company) {
            // Should never happen: oauthApprove always sets company before marking approved
            $this->em->flush();

            return $this->json(['error' => 'server_error', 'message' => 'Grant lacks company scope; please re-authorize'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $result = $this->createOrUpdateAppToken($user, $stripeAccountId, $company);
        $this->em->remove($deviceCode);
        $this->em->flush();

        return $result;
    }

    private function createOrUpdateAppToken($user, string $stripeAccountId, \App\Entity\Company $company): JsonResponse
    {
        $appToken = $this->tokenRepository->findByStripeAccountId($stripeAccountId);

        if (!$appToken) {
            $appToken = new StripeAppToken();
            $appToken->setStripeAccountId($stripeAccountId);
            $this->em->persist($appToken);
        }

        $accessToken = bin2hex(random_bytes(32));
        $refreshToken = bin2hex(random_bytes(32));

        $appToken->setUser($user);
        $appToken->setCompany($company);
        $appToken->setAccessToken($accessToken);
        $appToken->setRefreshToken($refreshToken);
        $appToken->setExpiresAt(new \DateTimeImmutable('+1 hour'));
        $appToken->setUpdatedAt(new \DateTimeImmutable());

        $this->em->flush();

        return $this->json([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]);
    }

    private function resolveAppToken(Request $request): ?StripeAppToken
    {
        $tokenValue = $request->headers->get('X-Stripe-App-Token');

        if (!$tokenValue) {
            return null;
        }

        return $this->tokenRepository->findValidByAccessToken($tokenValue);
    }
}
