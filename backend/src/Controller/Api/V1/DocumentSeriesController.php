<?php

namespace App\Controller\Api\V1;

use App\Entity\DocumentSeries;
use App\Repository\DocumentSeriesRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Service\DocumentSeries\NumberingDecisionPdfService;
use App\Service\DocumentSeries\NumberingDecisionService;
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
        private readonly NumberingDecisionService $numberingDecisionService,
        private readonly NumberingDecisionPdfService $numberingDecisionPdfService,
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

    /**
     * Decizia de numerotare: the yearly internal decision listing, per document
     * type, the series and the number range allocated for the year.
     */
    #[Route('/document-series/numbering-decision', methods: ['GET'], priority: 10)]
    public function numberingDecision(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company || !$this->organizationContext->ownsCompany($company)) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::SERIES_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $decision = $this->numberingDecisionService->build($company, $this->yearParam($request), $this->decisionOptions($request));
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($decision);
    }

    #[Route('/document-series/numbering-decision.pdf', methods: ['GET'], priority: 10)]
    public function numberingDecisionPdf(Request $request): Response
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company || !$this->organizationContext->ownsCompany($company)) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::SERIES_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $decision = $this->numberingDecisionService->build($company, $this->yearParam($request), $this->decisionOptions($request));
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        try {
            $pdf = $this->numberingDecisionPdfService->generate($company, $decision);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Failed to generate PDF: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $disposition = $request->query->getBoolean('download', true) ? 'attachment' : 'inline';

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('%s; filename="%s"', $disposition, NumberingDecisionPdfService::fileName($decision)),
            'Content-Length' => (string) strlen($pdf),
        ]);
    }

    private function yearParam(Request $request): int
    {
        $year = $request->query->get('year');
        if ($year === null || $year === '') {
            return (int) date('Y');
        }
        if (!\is_string($year) || !preg_match('/^\d{4}$/', $year)) {
            throw new \InvalidArgumentException('year must be a four-digit year.');
        }

        return (int) $year;
    }

    /** @return array<string, string|null> */
    private function decisionOptions(Request $request): array
    {
        $options = [];
        foreach (['decisionNumber', 'decisionDate', 'responsible', 'rangeSize'] as $key) {
            $value = $request->query->get($key);
            if ($value !== null && $value !== '') {
                $options[$key] = \is_string($value) ? trim(strip_tags($value)) : null;
            }
        }

        return $options;
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
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        $series = $this->documentSeriesRepository->find($uuid);
        if (!$series || $series->getCompany()?->getId()->toRfc4122() !== $company->getId()->toRfc4122()) {
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
    public function setDefault(string $uuid, Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        $series = $this->documentSeriesRepository->find($uuid);
        if (!$series || $series->getCompany()?->getId()->toRfc4122() !== $company->getId()->toRfc4122()) {
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
    public function delete(string $uuid, Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        $series = $this->documentSeriesRepository->find($uuid);
        if (!$series || $series->getCompany()?->getId()->toRfc4122() !== $company->getId()->toRfc4122()) {
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
