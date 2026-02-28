<?php

namespace App\Controller\Api\V1;

use App\Entity\StripeAppLinkingCode;
use App\Entity\StripeAppToken;
use App\Enum\DocumentStatus;
use App\Manager\InvoiceManager;
use App\Repository\CompanyRepository;
use App\Repository\InvoiceRepository;
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
        private readonly CompanyRepository $companyRepository,
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
            'linking_code' => $this->handleLinkingCode($data),
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

    #[Route('/linking-code', name: 'stripe_app_linking_code', methods: ['POST'])]
    public function createLinkingCode(): JsonResponse
    {
        $user = $this->getUser();

        if (!$user) {
            return $this->json(['error' => 'unauthorized', 'message' => 'Sesiunea a expirat. Reconecteaza-te din Setari.'], Response::HTTP_UNAUTHORIZED);
        }

        $linkingCode = new StripeAppLinkingCode();
        $linkingCode->setUser($user);
        $linkingCode->setCode(StripeAppLinkingCode::generateCode());
        $linkingCode->setExpiresAt(new \DateTimeImmutable('+5 minutes'));

        $this->em->persist($linkingCode);
        $this->em->flush();

        return $this->json([
            'code' => $linkingCode->getCode(),
            'expires_in' => 300,
        ]);
    }

    #[Route('/settings', name: 'stripe_app_settings_get', methods: ['GET'])]
    public function getSettings(Request $request): JsonResponse
    {
        $appToken = $this->resolveAppToken($request);

        if (!$appToken) {
            return $this->json(['error' => 'unauthorized', 'message' => 'Sesiunea a expirat. Reconecteaza-te din Setari.'], Response::HTTP_UNAUTHORIZED);
        }

        $user = $appToken->getUser();
        $companies = $this->getAccessibleCompanies($user);

        return $this->json([
            'autoMode' => $appToken->isAutoMode(),
            'defaultCompanyId' => $appToken->getCompany()?->getId()?->toRfc4122(),
            'companies' => array_map(fn ($c) => [
                'id' => $c->getId()->toRfc4122(),
                'name' => $c->getName(),
                'cif' => $c->getCif(),
            ], $companies),
        ]);
    }

    #[Route('/settings', name: 'stripe_app_settings_update', methods: ['PUT'])]
    public function updateSettings(Request $request): JsonResponse
    {
        $appToken = $this->resolveAppToken($request);

        if (!$appToken) {
            return $this->json(['error' => 'unauthorized', 'message' => 'Sesiunea a expirat. Reconecteaza-te din Setari.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['defaultCompanyId'])) {
            $company = $this->companyRepository->find(Uuid::fromString($data['defaultCompanyId']));
            if ($company) {
                $appToken->setCompany($company);
            }
        }

        if (isset($data['autoMode'])) {
            $appToken->setAutoMode((bool) $data['autoMode']);
        }

        $appToken->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $this->json([
            'autoMode' => $appToken->isAutoMode(),
            'defaultCompanyId' => $appToken->getCompany()?->getId()?->toRfc4122(),
        ]);
    }

    #[Route('/dashboard', name: 'stripe_app_dashboard', methods: ['GET'])]
    public function dashboard(Request $request): JsonResponse
    {
        $appToken = $this->resolveAppToken($request);

        if (!$appToken) {
            return $this->json(['error' => 'unauthorized', 'message' => 'Sesiunea a expirat. Reconecteaza-te din Setari.'], Response::HTTP_UNAUTHORIZED);
        }

        $company = $appToken->getCompany();

        if (!$company) {
            return $this->json([
                'counts' => ['draft' => 0, 'issued' => 0, 'sent_to_provider' => 0, 'validated' => 0, 'rejected' => 0, 'total' => 0],
                'recentInvoices' => [],
                'autoMode' => $appToken->isAutoMode(),
                'companyName' => null,
            ]);
        }

        $counts = $this->invoiceRepository->createQueryBuilder('i')
            ->select('i.status, COUNT(i.id) as cnt')
            ->where('i.company = :company')
            ->andWhere('i.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->groupBy('i.status')
            ->getQuery()
            ->getResult();

        $statusCounts = [
            'draft' => 0,
            'issued' => 0,
            'sent_to_provider' => 0,
            'validated' => 0,
            'rejected' => 0,
            'total' => 0,
        ];

        foreach ($counts as $row) {
            $status = $row['status'] instanceof DocumentStatus ? $row['status']->value : $row['status'];
            if (isset($statusCounts[$status])) {
                $statusCounts[$status] = (int) $row['cnt'];
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

        $invoicesData = array_map(fn ($inv) => [
            'id' => $inv->getId()->toRfc4122(),
            'invoiceNumber' => $inv->getNumber(),
            'issueDate' => $inv->getIssueDate()?->format('Y-m-d'),
            'total' => $inv->getTotal(),
            'currency' => $inv->getCurrency(),
            'receiverName' => $inv->getReceiverName(),
            'status' => $inv->getStatus()->value,
            'anafStatus' => $inv->getAnafStatus(),
        ], $recentInvoices);

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
            return $this->json(['error' => 'unauthorized', 'message' => 'Sesiunea a expirat. Reconecteaza-te din Setari.'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$appToken->getCompany()) {
            return $this->json(['error' => 'no_company', 'message' => 'Selecteaza o companie in setari'], Response::HTTP_BAD_REQUEST);
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
            return $this->json(['error' => 'unauthorized', 'message' => 'Sesiunea a expirat. Reconecteaza-te din Setari.'], Response::HTTP_UNAUTHORIZED);
        }

        $invoice = $this->invoiceRepository->find(Uuid::fromString($uuid));

        if (!$invoice) {
            return $this->json(['error' => 'not_found', 'message' => 'Factura nu a fost gasita.'], Response::HTTP_NOT_FOUND);
        }

        if ($invoice->getCompany() !== $appToken->getCompany()) {
            return $this->json(['error' => 'forbidden', 'message' => 'Nu ai permisiunea pentru aceasta factura.'], Response::HTTP_FORBIDDEN);
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

        return $this->createOrUpdateAppToken($user, $stripeAccountId);
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

    private function handleLinkingCode(array $data): JsonResponse
    {
        $code = strtoupper(trim($data['code'] ?? ''));
        $stripeAccountId = $data['stripe_account_id'] ?? null;

        if (!$code || !$stripeAccountId) {
            return $this->json(['error' => 'invalid_request', 'message' => 'Codul si contul Stripe sunt obligatorii.'], Response::HTTP_BAD_REQUEST);
        }

        $linkingCode = $this->em->getRepository(StripeAppLinkingCode::class)->findOneBy(['code' => $code]);

        if (!$linkingCode) {
            return $this->json(['error' => 'invalid_grant', 'message' => 'Cod invalid'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$linkingCode->isValid()) {
            $reason = $linkingCode->isUsed() ? 'Codul a fost deja folosit' : 'Codul a expirat';

            return $this->json(['error' => 'invalid_grant', 'message' => $reason], Response::HTTP_UNAUTHORIZED);
        }

        $linkingCode->setUsedAt(new \DateTimeImmutable());
        $user = $linkingCode->getUser();

        $result = $this->createOrUpdateAppToken($user, $stripeAccountId);

        $this->em->flush();

        return $result;
    }

    private function createOrUpdateAppToken($user, string $stripeAccountId): JsonResponse
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

    private function getAccessibleCompanies($user): array
    {
        $companies = [];
        foreach ($user->getOrganizationMemberships() as $membership) {
            $org = $membership->getOrganization();
            foreach ($org->getCompanies() as $company) {
                $companies[] = $company;
            }
        }

        return $companies;
    }
}
