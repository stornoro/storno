<?php

namespace App\Controller\Api\V1;

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
 * Parc auto: the company's vehicles, each with its next expiry (RCA, ITP, rovinietă …).
 *
 *   GET    /api/v1/vehicles                  list (?active=1&search=) with nextExpiry + counts per vehicle
 *   POST   /api/v1/vehicles                  create
 *   GET    /api/v1/vehicles/{uuid}           detail with its open expiries
 *   PATCH  /api/v1/vehicles/{uuid}           update
 *   DELETE /api/v1/vehicles/{uuid}           delete (its expiry items go with it)
 *   GET    /api/v1/vehicles/{uuid}/expiries  the vehicle's expiries (?includeClosed=1 for the history)
 *   POST   /api/v1/vehicles/{uuid}/expiries  add an expiry to the vehicle
 */
#[Route('/api/v1/vehicles')]
class VehicleController extends AbstractController
{
    public function __construct(
        private readonly OrganizationContext $organizationContext,
        private readonly EntityManagerInterface $em,
        private readonly VehicleRepository $vehicles,
        private readonly ExpiryItemRepository $expiries,
        private readonly ExpiryService $expiryService,
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
        $active = $request->query->has('active') ? filter_var($request->query->get('active'), FILTER_VALIDATE_BOOLEAN) : null;
        $search = $request->query->get('search');
        $items = $this->vehicles->findForCompany($company, $active, is_string($search) ? $search : null);

        $today = new \DateTimeImmutable('today');
        $expiries = [];
        foreach ($items as $vehicle) {
            $expiries[$vehicle->getId()->toRfc4122()] = ['nextExpiry' => null, 'counts' => ['expired' => 0, 'due' => 0, 'ok' => 0]];
        }
        foreach ($this->expiries->findForCompany($company) as $item) {
            $vid = $item->getVehicleId();
            if ($vid === null || !isset($expiries[$vid])) {
                continue;
            }
            $status = $item->statusOn($today);
            $expiries[$vid]['counts'][$status] = ($expiries[$vid]['counts'][$status] ?? 0) + 1;
            $expiries[$vid]['nextExpiry'] ??= ExpiryService::row($item, $today);
        }

        return $this->json(['data' => $items, 'expiries' => $expiries, 'total' => count($items), 'ownerships' => Vehicle::OWNERSHIPS, 'fuels' => Vehicle::FUELS], context: ['groups' => ['vehicle:list']]);
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
        $vehicle = (new Vehicle())->setCompany($company);
        try {
            $this->apply($vehicle, $input);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage(), 'code' => 'VALIDATION_FAILED'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->em->persist($vehicle);
        $this->em->flush();

        return $this->json($this->detail($vehicle), Response::HTTP_CREATED, context: ['groups' => ['vehicle:detail', 'expiry:list']]);
    }

    #[Route('/{uuid}', methods: ['GET'])]
    public function show(string $uuid): JsonResponse
    {
        $vehicle = $this->findOwned($uuid, Permission::SETTINGS_VIEW);
        if ($vehicle instanceof JsonResponse) {
            return $vehicle;
        }

        return $this->json($this->detail($vehicle), context: ['groups' => ['vehicle:detail', 'expiry:list']]);
    }

    #[Route('/{uuid}', methods: ['PATCH'])]
    public function update(string $uuid, Request $request): JsonResponse
    {
        $vehicle = $this->findOwned($uuid, Permission::SETTINGS_MANAGE);
        if ($vehicle instanceof JsonResponse) {
            return $vehicle;
        }
        $input = json_decode($request->getContent(), true);
        if (!is_array($input)) {
            return $this->json(['error' => 'Corpul cererii trebuie să fie JSON.', 'code' => 'INVALID_JSON'], Response::HTTP_BAD_REQUEST);
        }
        try {
            $this->apply($vehicle, $input);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage(), 'code' => 'VALIDATION_FAILED'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->em->flush();

        return $this->json($this->detail($vehicle), context: ['groups' => ['vehicle:detail', 'expiry:list']]);
    }

    #[Route('/{uuid}', methods: ['DELETE'])]
    public function delete(string $uuid): JsonResponse
    {
        $vehicle = $this->findOwned($uuid, Permission::SETTINGS_MANAGE);
        if ($vehicle instanceof JsonResponse) {
            return $vehicle;
        }
        $this->em->remove($vehicle);
        $this->em->flush();

        return $this->json(['deleted' => true]);
    }

    #[Route('/{uuid}/expiries', methods: ['GET'])]
    public function expiries(string $uuid, Request $request): JsonResponse
    {
        $vehicle = $this->findOwned($uuid, Permission::SETTINGS_VIEW);
        if ($vehicle instanceof JsonResponse) {
            return $vehicle;
        }
        $includeClosed = filter_var($request->query->get('includeClosed', false), FILTER_VALIDATE_BOOLEAN);
        $items = $this->expiries->findForCompany($vehicle->getCompany(), null, $vehicle, $includeClosed);
        $rows = array_map(static fn ($i) => ExpiryService::row($i), array_filter($items, static fn ($i) => !$i->isClosed()));

        return $this->json(['data' => $items, 'counts' => ExpiryService::counts(array_values($rows)), 'total' => count($items)], context: ['groups' => ['expiry:list']]);
    }

    #[Route('/{uuid}/expiries', methods: ['POST'])]
    public function addExpiry(string $uuid, Request $request): JsonResponse
    {
        $vehicle = $this->findOwned($uuid, Permission::SETTINGS_MANAGE);
        if ($vehicle instanceof JsonResponse) {
            return $vehicle;
        }
        $input = json_decode($request->getContent(), true);
        if (!is_array($input)) {
            return $this->json(['error' => 'Corpul cererii trebuie să fie JSON.', 'code' => 'INVALID_JSON'], Response::HTTP_BAD_REQUEST);
        }
        try {
            $item = $this->expiryService->create($vehicle->getCompany(), $input, $vehicle);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage(), 'code' => 'VALIDATION_FAILED'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($item, Response::HTTP_CREATED, context: ['groups' => ['expiry:detail']]);
    }

    /** @param array<string, mixed> $input */
    private function apply(Vehicle $vehicle, array $input): void
    {
        if (isset($input['plate'])) {
            $plate = mb_strtoupper(preg_replace('/\s+/', ' ', trim((string) $input['plate'])) ?? '');
            if ($plate === '' || mb_strlen($plate) > 20) {
                throw new \InvalidArgumentException('Numărul de înmatriculare este obligatoriu (max. 20 caractere).');
            }
            $vehicle->setPlate($plate);
        } elseif ($vehicle->getPlate() === '') {
            throw new \InvalidArgumentException('Numărul de înmatriculare (plate) este obligatoriu.');
        }
        foreach (['vin' => 32, 'make' => 60, 'model' => 60, 'driverName' => 120] as $key => $max) {
            if (array_key_exists($key, $input)) {
                $value = $input[$key] !== null ? trim((string) $input[$key]) : '';
                $vehicle->{'set' . ucfirst($key)}($value !== '' ? mb_substr($key === 'vin' ? mb_strtoupper($value) : $value, 0, $max) : null);
            }
        }
        if (array_key_exists('year', $input)) {
            $year = $input['year'] !== null && $input['year'] !== '' ? (int) $input['year'] : null;
            if ($year !== null && ($year < 1950 || $year > (int) date('Y') + 1)) {
                throw new \InvalidArgumentException('Anul fabricației este în afara intervalului.');
            }
            $vehicle->setYear($year);
        }
        if (array_key_exists('fuel', $input)) {
            $fuel = $input['fuel'] !== null && $input['fuel'] !== '' ? (string) $input['fuel'] : null;
            if ($fuel !== null && !in_array($fuel, Vehicle::FUELS, true)) {
                throw new \InvalidArgumentException('Combustibil necunoscut: ' . $fuel . '. Disponibile: ' . implode(', ', Vehicle::FUELS));
            }
            $vehicle->setFuel($fuel);
        }
        if (isset($input['ownership'])) {
            if (!in_array($input['ownership'], Vehicle::OWNERSHIPS, true)) {
                throw new \InvalidArgumentException('Tip de deținere necunoscut: ' . $input['ownership'] . '. Disponibile: ' . implode(', ', Vehicle::OWNERSHIPS));
            }
            $vehicle->setOwnership((string) $input['ownership']);
        }
        if (array_key_exists('notes', $input)) {
            $vehicle->setNotes($input['notes'] !== null && (string) $input['notes'] !== '' ? (string) $input['notes'] : null);
        }
        if (array_key_exists('active', $input)) {
            $vehicle->setActive(filter_var($input['active'], FILTER_VALIDATE_BOOLEAN));
        }
        $vehicle->touch();
    }

    /** @return array<string, mixed> */
    private function detail(Vehicle $vehicle): array
    {
        $items = $this->expiries->findForCompany($vehicle->getCompany(), null, $vehicle);
        $rows = array_map(static fn ($i) => ExpiryService::row($i), $items);

        return ['vehicle' => $vehicle, 'expiries' => $items, 'counts' => ExpiryService::counts($rows), 'nextExpiry' => $rows[0] ?? null];
    }

    private function findOwned(string $uuid, string $permission): Vehicle|JsonResponse
    {
        if (!$this->organizationContext->hasPermission($permission)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }
        $vehicle = $this->vehicles->find($uuid);
        if (!$vehicle instanceof Vehicle || !$this->organizationContext->ownsCompany($vehicle->getCompany())) {
            return $this->json(['error' => 'Vehicle not found.'], Response::HTTP_NOT_FOUND);
        }

        return $vehicle;
    }
}
