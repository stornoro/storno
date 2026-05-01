<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\VersionGateService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Hard-blocks mobile clients that are below the configured minimum
 * supported version. Runs early in the request cycle (after routing,
 * before auth) so a stale build cannot consume backend resources or
 * authenticate while it is supposed to be locked out.
 *
 * Allowlist: /api/v1/version (so the client can fetch the gate),
 * /api/auth/* (so the user can log in or refresh after updating),
 * /api/v1/telemetry (so blocked clients still phone home), and
 * /api/v1/system/health (so external probes keep working).
 *
 * The subscriber only acts when both X-Platform and X-App-Version are
 * present and Platform is one of ios|android|huawei. Web traffic (no
 * X-Platform) and unknown clients (no X-App-Version) pass through
 * unchanged — enforcement is opt-in via headers.
 */
final class MobileVersionGateSubscriber implements EventSubscriberInterface
{
    /**
     * Path patterns that are always reachable, even when the client is
     * below min. Anchored regexes against the request path (no host).
     */
    private const ALLOWLIST = [
        '#^/api/v1/version$#',
        '#^/api/auth(?:/.*)?$#',
        '#^/api/v1/telemetry$#',
        '#^/api/v1/system/health$#',
    ];

    private const MOBILE_PLATFORMS = ['ios', 'android', 'huawei'];

    public function __construct(private readonly VersionGateService $versionGate)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Priority 10 → runs after the router (32) so the path is parsed,
        // and before the firewall (8) so a blocked client cannot even
        // authenticate. We never depend on the auth context here.
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 10],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $platform = strtolower((string) $request->headers->get('X-Platform', ''));
        $clientVersion = $request->headers->get('X-App-Version');

        // Only enforce when both headers are explicitly set and the
        // platform is one of the mobile surfaces we manage.
        if ($clientVersion === null || $clientVersion === '' || !in_array($platform, self::MOBILE_PLATFORMS, true)) {
            return;
        }

        $path = $request->getPathInfo();
        foreach (self::ALLOWLIST as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return;
            }
        }

        $gate = $this->versionGate->evaluate($platform, $clientVersion);
        if ($gate === null || $gate['tier'] !== VersionGateService::TIER_BLOCKING) {
            return;
        }

        $body = [
            'type' => 'https://storno.ro/errors/upgrade-required',
            'title' => 'Upgrade required',
            'status' => 426,
            'detail' => 'Your client version is below the minimum supported version. Please update from your app store.',
            'tier' => $gate['tier'],
            'min' => $gate['min'],
            'latest' => $gate['latest'],
            'storeUrl' => $gate['storeUrl'],
            'releaseNotesUrl' => $gate['releaseNotesUrl'],
            'message' => $gate['message'],
        ];

        $event->setResponse(new JsonResponse($body, JsonResponse::HTTP_UPGRADE_REQUIRED));
    }
}
