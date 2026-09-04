<?php

namespace App\Controller\Api\V1;

use App\Entity\Company;
use App\Entity\User;
use App\Enum\OrganizationRole;
use App\Repository\CompanyRepository;
use App\Security\OrganizationContext;
use App\Service\Centrifugo\CentrifugoService;
use App\Service\LicenseManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/centrifugo')]
class CentrifugoController extends AbstractController
{
    /**
     * Channel namespaces a client may subscribe to on a company channel.
     * Must match the `namespaces` list in config/centrifugo.json.
     */
    private const COMPANY_NAMESPACES = ['invoices', 'notifications', 'dashboard', 'user', 'import', 'backup'];

    private const MAX_CHANNELS_PER_REQUEST = 50;

    public function __construct(
        private readonly CentrifugoService $centrifugo,
        private readonly OrganizationContext $organizationContext,
        private readonly LicenseManager $licenseManager,
        private readonly CompanyRepository $companyRepository,
    ) {}

    #[Route('/connection-token', methods: ['POST'])]
    public function connectionToken(): JsonResponse
    {
        $user = $this->getUser();

        $org = $this->organizationContext->getOrganization();
        if ($org && !$this->licenseManager->canReceiveRealtimeNotifications($org)) {
            return $this->json([
                'error' => 'Realtime notifications are not available on your plan.',
                'code' => 'PLAN_LIMIT',
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        $token = $this->centrifugo->generateConnectionToken(
            (string) $user->getId(),
            time() + 3600,
            ['email' => $user->getEmail()],
        );

        return $this->json(['token' => $token]);
    }

    /**
     * Issue subscription tokens. Only two channel shapes are ever granted:
     *  - `notifications:user_<id>` for the caller's own user id
     *  - `<namespace>:company_<uuid>` for a company the caller can access
     * Anything else is refused with 403 — the server-side Centrifugo config
     * disables client-side subscribe, so this endpoint is the only gate.
     */
    #[Route('/subscription-token', methods: ['POST'])]
    public function subscriptionToken(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $org = $this->organizationContext->getOrganization();
        if ($org && !$this->licenseManager->canReceiveRealtimeNotifications($org)) {
            return $this->json([
                'error' => 'Realtime notifications are not available on your plan.',
                'code' => 'PLAN_LIMIT',
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $channel = $payload['channel'] ?? '';
        $channels = $payload['channels'] ?? [];

        // Batch mode: multiple channels in one request
        if (!empty($channels) && is_array($channels)) {
            if (count($channels) > self::MAX_CHANNELS_PER_REQUEST) {
                return $this->json(['error' => 'Too many channels requested.'], Response::HTTP_BAD_REQUEST);
            }

            $expiry = time() + 3600;
            $tokens = [];
            foreach ($channels as $ch) {
                if (!is_string($ch) || $ch === '') {
                    continue;
                }
                if (!$this->isChannelAllowed($ch, $user)) {
                    return $this->json(['error' => 'Access denied to channel.'], Response::HTTP_FORBIDDEN);
                }
                $tokens[$ch] = $this->centrifugo->generateSubscriptionToken(
                    (string) $user->getId(),
                    $ch,
                    $expiry,
                );
            }

            return $this->json(['tokens' => $tokens]);
        }

        if (!$channel || !is_string($channel)) {
            return $this->json(['error' => 'Channel is required'], 400);
        }

        if (!$this->isChannelAllowed($channel, $user)) {
            return $this->json(['error' => 'Access denied to channel.'], Response::HTTP_FORBIDDEN);
        }

        $token = $this->centrifugo->generateSubscriptionToken(
            (string) $user->getId(),
            $channel,
            time() + 3600,
        );

        return $this->json(['token' => $token]);
    }

    private function isChannelAllowed(string $channel, User $user): bool
    {
        if (strlen($channel) > 128) {
            return false;
        }

        // Personal notification channel — only for the caller's own id.
        if (preg_match('/^notifications:user_(.+)$/', $channel, $m)) {
            return hash_equals((string) $user->getId(), $m[1]);
        }

        // Company channel — company must belong to the caller's org and be accessible.
        if (preg_match('/^([a-z]+):company_([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i', $channel, $m)) {
            if (!in_array(strtolower($m[1]), self::COMPANY_NAMESPACES, true)) {
                return false;
            }

            try {
                $company = $this->companyRepository->find(Uuid::fromString($m[2]));
            } catch (\Throwable) {
                return false;
            }

            return $company instanceof Company && $this->canAccessCompany($company);
        }

        return false;
    }

    /**
     * Mirrors OrganizationContext::resolveCompany(): the company must be owned
     * by the caller's organization, and non-owner/admin members must have been
     * granted per-company access.
     */
    private function canAccessCompany(Company $company): bool
    {
        if (!$this->organizationContext->ownsCompany($company)) {
            return false;
        }

        $membership = $this->organizationContext->getMembership();
        if ($membership === null) {
            // ownsCompany() only passes without a membership for super admins.
            return true;
        }

        $role = $membership->getRole();
        if ($role === OrganizationRole::OWNER || $role === OrganizationRole::ADMIN) {
            return true;
        }

        return $membership->hasAccessToCompany($company);
    }
}
