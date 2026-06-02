<?php

namespace App\EventListener;

use App\Repository\WhiteLabelConfigRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Allows cross-origin API calls from organizations' verified custom domains.
 *
 * nelmio/cors only knows the platform frontend origin; a Business org serving
 * the app on its own domain produces an Origin nelmio won't allow. This runs
 * after nelmio and, when the Origin matches a verified custom domain, adds the
 * CORS headers (including for preflight OPTIONS).
 */
class CustomDomainCorsListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly WhiteLabelConfigRepository $repository,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Negative priority → run after nelmio's CORS listener.
        return [KernelEvents::RESPONSE => ['onResponse', -256]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $origin = $request->headers->get('Origin');
        if (!$origin) {
            return;
        }

        $response = $event->getResponse();

        // Already allowed by nelmio (platform origin) — nothing to do.
        if ($response->headers->has('Access-Control-Allow-Origin')) {
            return;
        }

        $host = parse_url($origin, PHP_URL_HOST);
        if (!$host || !$this->repository->findVerifiedByCustomDomain($host)) {
            return;
        }

        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Vary', trim($response->headers->get('Vary', '') . ', Origin', ', '));

        if ($request->isMethod('OPTIONS')) {
            $response->headers->set('Access-Control-Allow-Methods', 'GET, OPTIONS, POST, PUT, PATCH, DELETE');
            $response->headers->set('Access-Control-Allow-Headers', 'Accept, Content-Type, Authorization, X-Company, X-Organization');
            $response->headers->set('Access-Control-Max-Age', '3600');
        }
    }
}
