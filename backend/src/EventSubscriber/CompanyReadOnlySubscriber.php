<?php

namespace App\EventSubscriber;

use App\Security\OrganizationContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Blocks mutating API requests addressed to a read-only company.
 *
 * Companies become read-only when an organization has more companies than its
 * plan allows (see CompanyReadOnlyService). The company is resolved exactly the
 * way controllers resolve it — X-Company header / ?company query, with the
 * single-company fallback — so a request cannot dodge the check by omitting the
 * header. Entities addressed by their own UUID are not resolved here.
 */
class CompanyReadOnlySubscriber implements EventSubscriberInterface
{
    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private const API_PREFIX = '/api/v1/';

    /**
     * Company management routes that must keep working on a read-only company:
     * restoring a soft-deleted company and swapping which company is writable.
     */
    private const EXEMPT_PATH_PATTERN = '#^/api/v1/companies/[^/]+/(restore|set-active)$#';

    public function __construct(
        private readonly OrganizationContext $organizationContext,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onKernelController', 0],
        ];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!in_array($request->getMethod(), self::MUTATING_METHODS, true)) {
            return;
        }

        $path = $request->getPathInfo();
        if (!str_starts_with($path, self::API_PREFIX)) {
            return;
        }

        if (preg_match(self::EXEMPT_PATH_PATTERN, $path) === 1) {
            return;
        }

        try {
            $company = $this->organizationContext->resolveCompany($request);
        } catch (\InvalidArgumentException) {
            // Malformed company id — the controller returns its own 4xx
            return;
        }

        if ($company === null) {
            return;
        }

        if ($company->isReadOnly()) {
            throw new AccessDeniedHttpException('company.readOnly');
        }
    }
}
