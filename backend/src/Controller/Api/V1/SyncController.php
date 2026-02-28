<?php

namespace App\Controller\Api\V1;

use App\Exception\AnafRateLimitException;
use App\Repository\CompanyRepository;
use App\Repository\InvoiceRepository;
use App\Repository\OrganizationMembershipRepository;
use App\Security\OrganizationContext;
use App\Service\Anaf\AnafTokenResolver;
use App\Message\Anaf\SyncCompanyMessage;
use App\Service\Anaf\EFacturaClient;
use App\Service\Centrifugo\CentrifugoService;
use App\Service\LicenseManager;
use App\Service\NotificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/sync')]
class SyncController extends AbstractController
{
    public function __construct(
        private readonly AnafTokenResolver $tokenResolver,
        private readonly EFacturaClient $eFacturaClient,
        private readonly OrganizationContext $organizationContext,
        private readonly CompanyRepository $companyRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly LicenseManager $licenseManager,
        private readonly NotificationService $notificationService,
        private readonly OrganizationMembershipRepository $membershipRepository,
        private readonly CentrifugoService $centrifugo,
        private readonly MessageBusInterface $messageBus,
        private readonly string $env,
    ) {}

    #[Route('/trigger', methods: ['POST'])]
    public function trigger(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        $org = $company->getOrganization();
        if ($org && $this->env !== 'dev') {
            $syncInterval = $this->licenseManager->getSyncInterval($org);
            $lastSyncedAt = $company->getLastSyncedAt();
            if ($syncInterval > 0 && $lastSyncedAt !== null) {
                $nextSyncAt = $lastSyncedAt->modify("+{$syncInterval} seconds");
                $now = new \DateTimeImmutable();
                if ($nextSyncAt > $now) {
                    $waitSeconds = $nextSyncAt->getTimestamp() - $now->getTimestamp();
                    if ($waitSeconds >= 3600) {
                        $hours = (int) ceil($waitSeconds / 3600);
                        $waitLabel = "{$hours}h";
                    } else {
                        $minutes = (int) ceil($waitSeconds / 60);
                        $waitLabel = "{$minutes} min";
                    }
                    $plan = $this->licenseManager->getEffectivePlan($org);
                    return $this->json([
                        'error' => "Planul {$plan} permite sincronizare la fiecare " . $this->formatSyncInterval($syncInterval) . ". Urmatoarea sincronizare disponibila in {$waitLabel}.",
                        'code' => 'SYNC_RATE_LIMITED',
                        'retryAfter' => $waitSeconds,
                    ], Response::HTTP_TOO_MANY_REQUESTS, [
                        'Retry-After' => $waitSeconds,
                    ]);
                }
            }
        }

        // Pre-validate: resolve token and verify it works for this company's CIF
        $token = $this->tokenResolver->resolve($company);
        if (!$token) {
            $errorMsg = 'Nu exista un token ANAF valid. Conectati-va mai intai la ANAF.';
            $this->notifySyncError($company, $errorMsg, 'NO_TOKEN');

            return $this->json([
                'error' => $errorMsg,
                'code' => 'NO_TOKEN',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $cif = (string) $company->getCif();
            $validation = $this->eFacturaClient->validateToken($cif, $token);
            if (!$validation['valid']) {
                // Invalidate cached CIF for this token so it's not reused
                $this->tokenResolver->invalidateCifCache($company);

                $errorMsg = $validation['error'] ?? 'Token-ul ANAF nu are acces la aceasta companie.';
                $this->notifySyncError($company, $errorMsg, 'TOKEN_INVALID_FOR_CIF');

                return $this->json([
                    'error' => $errorMsg,
                    'code' => 'TOKEN_INVALID_FOR_CIF',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        } catch (AnafRateLimitException $e) {
            return $this->json([
                'error' => 'Limita de apeluri ANAF a fost atinsa. Incercati din nou mai tarziu.',
                'retryAfter' => $e->retryAfter,
            ], Response::HTTP_TOO_MANY_REQUESTS, [
                'Retry-After' => $e->retryAfter,
            ]);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $days = isset($data['days']) ? (int) $data['days'] : null;

        $this->messageBus->dispatch(new SyncCompanyMessage(
            companyId: $company->getId()->toRfc4122(),
            daysOverride: $days,
        ));

        return $this->json(['status' => 'queued'], Response::HTTP_ACCEPTED);
    }

    #[Route('/status', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        $token = $this->tokenResolver->resolve($company);
        $hasToken = $token !== null;
        $tokenError = null;

        // If token exists, validate it actually works for this company's CIF
        if ($hasToken) {
            try {
                $cif = (string) $company->getCif();
                $validation = $this->eFacturaClient->validateToken($cif, $token);
                if (!$validation['valid']) {
                    $hasToken = false;
                    $tokenError = $validation['error'];
                    $this->tokenResolver->invalidateCifCache($company);
                }
            } catch (AnafRateLimitException) {
                // On rate limit, assume token is valid to avoid blocking the UI
            } catch (\Throwable) {
                // On network errors, assume token is valid
            }
        }

        $org = $company->getOrganization();
        $syncInterval = $org ? $this->licenseManager->getSyncInterval($org) : 86400;

        return $this->json([
            'syncEnabled' => $company->isSyncEnabled(),
            'lastSyncedAt' => $company->getLastSyncedAt()?->format('c'),
            'hasValidToken' => $hasToken,
            'tokenError' => $tokenError,
            'syncDaysBack' => $company->getSyncDaysBack(),
            'syncInterval' => $syncInterval,
        ]);
    }

    #[Route('/log', methods: ['GET'])]
    public function log(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        $limit = min((int) $request->query->get('limit', 10), 50);
        $offset = max((int) $request->query->get('offset', 0), 0);

        // Return recent sync activity — latest synced invoices
        $qb = $this->invoiceRepository->createQueryBuilder('i')
            ->where('i.company = :company')
            ->andWhere('i.syncedAt IS NOT NULL')
            ->setParameter('company', $company)
            ->orderBy('i.syncedAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $recentInvoices = $qb->getQuery()->getResult();

        $total = (int) $this->invoiceRepository->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.company = :company')
            ->andWhere('i.syncedAt IS NOT NULL')
            ->setParameter('company', $company)
            ->getQuery()
            ->getSingleScalarResult();

        return $this->json([
            'entries' => array_map(function ($invoice) {
                return [
                    'id' => (string) $invoice->getId(),
                    'number' => $invoice->getNumber(),
                    'direction' => $invoice->getDirection()?->value,
                    'status' => $invoice->getStatus()->value,
                    'syncedAt' => $invoice->getSyncedAt()?->format('c'),
                    'senderName' => $invoice->getSenderName(),
                    'receiverName' => $invoice->getReceiverName(),
                    'total' => $invoice->getTotal(),
                    'currency' => $invoice->getCurrency(),
                ];
            }, $recentInvoices),
            'total' => min($total, 50),
        ]);
    }

    private function notifySyncError(\App\Entity\Company $company, string $errorMsg, string $code): void
    {
        try {
            $channel = 'invoices:company_' . $company->getId()->toRfc4122();
            $this->centrifugo->publish($channel, [
                'type' => 'sync.error',
                'error' => $errorMsg,
                'code' => $code,
                'companyId' => $company->getId()->toRfc4122(),
            ]);

            $users = $this->membershipRepository->findActiveUsersByCompany($company);
            foreach ($users as $user) {
                $this->notificationService->createNotification(
                    $user,
                    'sync.error',
                    'Eroare sincronizare e-Factura',
                    sprintf('%s — %s', $company->getName(), $errorMsg),
                    [
                        'companyId' => $company->getId()->toRfc4122(),
                        'code' => $code,
                    ],
                );
            }
        } catch (\Throwable) {
            // Don't let notification failures break the sync error response
        }
    }

    private function formatSyncInterval(int $seconds): string
    {
        if ($seconds >= 86400) {
            $hours = (int) ($seconds / 3600);
            return $hours >= 24 ? ((int) ($hours / 24)) . ' zi' . ((int) ($hours / 24) > 1 ? 'le' : '') : "{$hours}h";
        }
        if ($seconds >= 3600) {
            return ((int) ($seconds / 3600)) . 'h';
        }
        return ((int) ($seconds / 60)) . ' min';
    }

    private function resolveCompany(Request $request): ?\App\Entity\Company
    {
        return $this->organizationContext->resolveCompany($request);
    }
}
