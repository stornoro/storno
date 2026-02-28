<?php

namespace App\Controller\Api\V1;

use App\Entity\DocumentSeries;
use App\Repository\DocumentSeriesRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1')]
class DocumentSeriesController extends AbstractController
{
    private const VALID_TYPES = ['invoice', 'proforma', 'credit_note', 'delivery_note', 'receipt', 'voucher'];

    public function __construct(
        private readonly DocumentSeriesRepository $documentSeriesRepository,
        private readonly OrganizationContext $organizationContext,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/document-series', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::SERIES_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $series = $this->documentSeriesRepository->findByCompany($company);

        return $this->json(['data' => $series], context: ['groups' => ['docseries:list']]);
    }

    #[Route('/document-series', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::SERIES_MANAGE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $prefix = $data['prefix'] ?? null;

        if (!$prefix) {
            return $this->json(['error' => 'Field "prefix" is required.'], Response::HTTP_BAD_REQUEST);
        }

        $existing = $this->documentSeriesRepository->findByPrefix($company, $prefix);
        if ($existing) {
            return $this->json(['error' => 'Series with this prefix already exists.'], Response::HTTP_CONFLICT);
        }

        $type = $data['type'] ?? 'invoice';
        if (!in_array($type, self::VALID_TYPES, true)) {
            return $this->json([
                'error' => 'Invalid type. Valid types: ' . implode(', ', self::VALID_TYPES),
            ], Response::HTTP_BAD_REQUEST);
        }

        $series = new DocumentSeries();
        $series->setCompany($company);
        $series->setPrefix($prefix);
        $series->setType($type);
        $series->setCurrentNumber($data['currentNumber'] ?? 0);
        $series->setActive($data['active'] ?? true);

        if ($data['isDefault'] ?? false) {
            $this->documentSeriesRepository->clearDefaultsForType($company, $type);
            $series->setIsDefault(true);
        }

        $this->entityManager->persist($series);
        $this->entityManager->flush();

        return $this->json($series, Response::HTTP_CREATED, context: ['groups' => ['docseries:detail']]);
    }

    #[Route('/document-series/{uuid}', methods: ['PATCH'])]
    public function update(string $uuid, Request $request): JsonResponse
    {
        $series = $this->documentSeriesRepository->find($uuid);
        if (!$series) {
            return $this->json(['error' => 'Document series not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::SERIES_MANAGE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['currentNumber'])) {
            $series->setCurrentNumber((int) $data['currentNumber']);
        }
        if (isset($data['active'])) {
            $series->setActive((bool) $data['active']);
        }
        if (isset($data['type'])) {
            if (!in_array($data['type'], self::VALID_TYPES, true)) {
                return $this->json([
                    'error' => 'Invalid type. Valid types: ' . implode(', ', self::VALID_TYPES),
                ], Response::HTTP_BAD_REQUEST);
            }
            $series->setType($data['type']);
        }
        if (isset($data['isDefault'])) {
            if ($data['isDefault']) {
                $this->documentSeriesRepository->clearDefaultsForType($series->getCompany(), $series->getType());
                $series->setIsDefault(true);
            } else {
                $series->setIsDefault(false);
            }
        }

        $this->entityManager->flush();

        return $this->json($series, context: ['groups' => ['docseries:detail']]);
    }

    #[Route('/document-series/{uuid}/set-default', methods: ['POST'])]
    public function setDefault(string $uuid): JsonResponse
    {
        $series = $this->documentSeriesRepository->find($uuid);
        if (!$series) {
            return $this->json(['error' => 'Document series not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::SERIES_MANAGE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $this->documentSeriesRepository->clearDefaultsForType($series->getCompany(), $series->getType());
        $series->setIsDefault(true);
        $this->entityManager->flush();

        return $this->json($series, context: ['groups' => ['docseries:detail']]);
    }

    #[Route('/document-series/{uuid}', methods: ['DELETE'])]
    public function delete(string $uuid): JsonResponse
    {
        $series = $this->documentSeriesRepository->find($uuid);
        if (!$series) {
            return $this->json(['error' => 'Document series not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::SERIES_MANAGE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $this->entityManager->remove($series);
        $this->entityManager->flush();

        return $this->json(['message' => 'Document series deleted.']);
    }
}
