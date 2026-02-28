<?php

namespace App\EventSubscriber;

use App\Repository\CompanyRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

class CompanyReadOnlySubscriber implements EventSubscriberInterface
{
    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private readonly CompanyRepository $companyRepository,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onKernelController', 0],
        ];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $request = $event->getRequest();

        if (!in_array($request->getMethod(), self::MUTATING_METHODS, true)) {
            return;
        }

        // Skip non-API routes and webhook/auth routes
        $route = $request->attributes->get('_route', '');
        if (!str_starts_with($route, 'app_api_v1_') && !str_starts_with($route, 'api_v1_')) {
            return;
        }

        // Skip company management routes (set-active, create, restore) — they need to work on read-only companies
        $path = $request->getPathInfo();
        if (str_contains($path, '/set-active') || str_contains($path, '/restore')) {
            return;
        }

        $companyId = $request->headers->get('X-Company') ?? $request->query->get('company');
        if (!$companyId) {
            return;
        }

        try {
            $company = $this->companyRepository->find(Uuid::fromString($companyId));
        } catch (\InvalidArgumentException) {
            return;
        }

        if (!$company) {
            return;
        }

        if ($company->isReadOnly()) {
            throw new AccessDeniedHttpException('company.readOnly');
        }
    }
}
