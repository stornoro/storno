<?php

namespace App\Controller\Api\V1;

use App\Entity\ExpiryItem;
use App\Entity\Vehicle;
use App\Repository\ExpiryItemRepository;
use App\Repository\VehicleRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Service\Fleet\ExpiryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Expiry items: everything the company must renew on a date — vehicle documents and
 * company-level ones (certificat digital, contracts, autorizații).
 *
 *   GET    /api/v1/expiries                 list (?kind=&vehicleId=&companyLevel=1&includeClosed=1), expired first
 *   GET    /api/v1/expiries/upcoming?days=60  open items expiring within `days` (expired included) as flat rows + counts
 *   GET    /api/v1/expiries/kinds           the kinds with their default labels and usual validity
 *   POST   /api/v1/expiries                 create ({kind, label?, number?, provider?, validFrom?, expiresAt, remindDaysBefore?, vehicleId?, notes?})
 *   GET    /api/v1/expiries/{uuid}          detail with its history (the items it renewed)
 *   PATCH  /api/v1/expiries/{uuid}          update
 *   DELETE /api/v1/expiries/{uuid}          delete
 *   POST   /api/v1/expiries/{uuid}/renew    {expiresAt?, validFrom?, number?, provider?, notes?} → the next item; the old one is closed
 */
#[Route('/api/v1/expiries')]
class ExpiryController extends AbstractController
{
    public function __construct(
        private readonly OrganizationContext $organizationContext,
        private readonly EntityManagerInterface $em,
        private readonly ExpiryItemRepository $repository,
        private readonly VehicleRepository $vehicles,
        private readonly ExpiryService $service,
    ) {}

    #[Route('', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->organizationContext->hasPermission(Permission::SETTINGS_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }
        $kind = $request->query->get('kind');
        $vehicle = null;
        $vehicleId = $request->query->get('vehicleId');
        if (is_string($vehicleId) && $vehicleId !== '') {
            $vehicle = $this->vehicles->find($vehicleId);
            if (!$vehicle instanceof Vehicle || $vehicle->getCompany() !== $company) {
                return $this->json(['error' => 'Vehicle not found.'], Response::HTTP_NOT_FOUND);
            }
        }
        $includeClosed = filter_var($request->query->get('includeClosed', false), FILTER_VALIDATE_BOOLEAN);
        $companyLevel = filter_var($request->query->get('companyLevel', false), FILTER_VALIDATE_BOOLEAN);
        $items = $this->repository->findForCompany($company, is_string($kind) ? $kind : null, $vehicle, $includeClosed, $companyLevel);
        $open = array_values(array_filter($items, static fn (ExpiryItem $i) => !$i->isClosed()));

        return $this->json([
            'data' => $items,
            'counts' => ExpiryService::counts(array_map(static fn ($i) => ExpiryService::row($i), $open)),
            'total' => count($items),
            'kinds' => ExpiryItem::KINDS,
        ], context: ['groups' => ['expiry:list']]);
    }

    #[Route('/upcoming', methods: ['GET'])]
    public function upcoming(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->organizationContext->hasPermission(Permission::SETTINGS_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }
        $days = (int) $request->query->get('days', 60);
        if ($days < 1 || $days > 730) {
            return $this->json(['error' => 'days: între 1 și 730.'], Response::HTTP_BAD_REQUEST);
        }
        $rows = $this->service->upcoming($company, $days);

        return $this->json(['data' => $rows, 'counts' => ExpiryService::counts($rows), 'days' => $days]);
    }

    #[Route('/kinds', methods: ['GET'])]
    public function kinds(): JsonResponse
    {
        $out = [];
        foreach (ExpiryItem::KINDS as $kind) {
            $out[] = ['kind' => $kind, 'label' => ExpiryService::defaultLabel($kind), 'vehicle' => in_array($kind, ExpiryItem::VEHICLE_KINDS, true), 'months' => ExpiryItem::DEFAULT_MONTHS[$kind] ?? null];
        }

        return $this->json(['data' => $out]);
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->organizationContext->hasPermission(Permission::SETTINGS_MANAGE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }
        $input = json_decode($request->getContent(), true);
        if (!is_array($input)) {
            return $this->json(['error' => 'Corpul cererii trebuie să fie JSON.', 'code' => 'INVALID_JSON'], Response::HTTP_BAD_REQUEST);
        }
        $vehicle = null;
        if (isset($input['vehicleId']) && (string) $input['vehicleId'] !== '') {
            $vehicle = $this->vehicles->find((string) $input['vehicleId']);
            if (!$vehicle instanceof Vehicle || $vehicle->getCompany() !== $company) {
                return $this->json(['error' => 'Vehicle not found.', 'code' => 'VALIDATION_FAILED'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }
        try {
            $item = $this->service->create($company, $input, $vehicle);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage(), 'code' => 'VALIDATION_FAILED'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($item, Response::HTTP_CREATED, context: ['groups' => ['expiry:detail']]);
    }

    #[Route('/{uuid}', methods: ['GET'])]
    public function show(string $uuid): JsonResponse
    {
        $item = $this->findOwned($uuid, Permission::SETTINGS_VIEW);
        if ($item instanceof JsonResponse) {
            return $item;
        }

        return $this->json(['item' => $item, 'history' => $this->history($item)], context: ['groups' => ['expiry:detail', 'expiry:list']]);
    }

    #[Route('/{uuid}', methods: ['PATCH'])]
    public function update(string $uuid, Request $request): JsonResponse
    {
        $item = $this->findOwned($uuid, Permission::SETTINGS_MANAGE);
        if ($item instanceof JsonResponse) {
            return $item;
        }
        $input = json_decode($request->getContent(), true);
        if (!is_array($input)) {
            return $this->json(['error' => 'Corpul cererii trebuie să fie JSON.', 'code' => 'INVALID_JSON'], Response::HTTP_BAD_REQUEST);
        }
        try {
            if (array_key_exists('vehicleId', $input)) {
                $vehicle = null;
                if ($input['vehicleId'] !== null && (string) $input['vehicleId'] !== '') {
                    $vehicle = $this->vehicles->find((string) $input['vehicleId']);
                    if (!$vehicle instanceof Vehicle || $vehicle->getCompany() !== $item->getCompany()) {
                        throw new \InvalidArgumentException('Vehicle not found.');
                    }
                }
                $item->setVehicle($vehicle);
            }
            $this->service->apply($item, $input);
            if (array_key_exists('closed', $input)) {
                $item->setClosedAt(filter_var($input['closed'], FILTER_VALIDATE_BOOLEAN) ? ($item->getClosedAt() ?? new \DateTimeImmutable()) : null);
            }
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage(), 'code' => 'VALIDATION_FAILED'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->em->flush();

        return $this->json(['item' => $item, 'history' => $this->history($item)], context: ['groups' => ['expiry:detail', 'expiry:list']]);
    }

    #[Route('/{uuid}', methods: ['DELETE'])]
    public function delete(string $uuid): JsonResponse
    {
        $item = $this->findOwned($uuid, Permission::SETTINGS_MANAGE);
        if ($item instanceof JsonResponse) {
            return $item;
        }
        $this->em->remove($item);
        $this->em->flush();

        return $this->json(['deleted' => true]);
    }

    #[Route('/{uuid}/renew', methods: ['POST'])]
    public function renew(string $uuid, Request $request): JsonResponse
    {
        $item = $this->findOwned($uuid, Permission::SETTINGS_MANAGE);
        if ($item instanceof JsonResponse) {
            return $item;
        }
        $input = json_decode($request->getContent(), true) ?: [];
        try {
            $next = $this->service->renew($item, is_array($input) ? $input : []);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage(), 'code' => 'VALIDATION_FAILED'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['item' => $next, 'previous' => $item], Response::HTTP_CREATED, context: ['groups' => ['expiry:detail', 'expiry:list']]);
    }

    /** @return list<ExpiryItem> the chain of items this one renewed, newest first */
    private function history(ExpiryItem $item): array
    {
        $chain = [];
        $cursor = $item->getRenewedFrom();
        while ($cursor !== null && count($chain) < 50) {
            $chain[] = $cursor;
            $cursor = $cursor->getRenewedFrom();
        }

        return $chain;
    }

    private function findOwned(string $uuid, string $permission): ExpiryItem|JsonResponse
    {
        if (!$this->organizationContext->hasPermission($permission)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }
        $item = $this->repository->find($uuid);
        if (!$item instanceof ExpiryItem || !$this->organizationContext->ownsCompany($item->getCompany())) {
            return $this->json(['error' => 'Expiry item not found.'], Response::HTTP_NOT_FOUND);
        }

        return $item;
    }
}
