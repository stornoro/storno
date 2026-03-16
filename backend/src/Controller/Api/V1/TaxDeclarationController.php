<?php

namespace App\Controller\Api\V1;

use App\Enum\DeclarationStatus;
use App\Manager\TaxDeclarationManager;
use App\Message\Declaration\CheckDeclarationStatusMessage;
use App\Repository\TaxDeclarationRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Constants\Pagination;
use App\Service\Anaf\AnafTokenResolver;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1')]
class TaxDeclarationController extends AbstractController
{
    public function __construct(
        private readonly TaxDeclarationManager $manager,
        private readonly OrganizationContext $organizationContext,
        private readonly FilesystemOperator $defaultStorage,
        private readonly AnafTokenResolver $anafTokenResolver,
        private readonly MessageBusInterface $messageBus,
        private readonly EntityManagerInterface $entityManager,
        private readonly TaxDeclarationRepository $declarationRepository,
    ) {}

    #[Route('/declarations', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $filters = $request->query->all();
        $page = $request->query->getInt('page', 1);
        $limit = Pagination::clamp($request->query->getInt('limit', Pagination::DEFAULT_LIMIT));

        $result = $this->manager->listByCompany($company, $filters, $page, $limit);

        return $this->json($result, context: ['groups' => ['declaration:list']]);
    }

    #[Route('/declarations/{uuid}', methods: ['GET'])]
    public function show(string $uuid): JsonResponse
    {
        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $declaration = $this->manager->find($uuid);
        if (!$declaration) {
            return $this->json(['error' => 'Declaration not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($declaration, context: ['groups' => ['declaration:detail']]);
    }

    #[Route('/declarations', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_SUBMIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        /** @var \App\Entity\User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!$data || empty($data['type']) || empty($data['year']) || empty($data['month'])) {
            return $this->json(['error' => 'Missing required fields: type, year, month.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $declaration = $this->manager->create($company, $data, $user);

            return $this->json($declaration, Response::HTTP_CREATED, context: ['groups' => ['declaration:detail']]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/declarations/{uuid}', methods: ['PATCH'])]
    public function update(string $uuid, Request $request): JsonResponse
    {
        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_SUBMIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $declaration = $this->manager->find($uuid);
        if (!$declaration) {
            return $this->json(['error' => 'Declaration not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        try {
            $declaration = $this->manager->update($declaration, $data);

            return $this->json($declaration, context: ['groups' => ['declaration:detail']]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/declarations/{uuid}', methods: ['DELETE'])]
    public function delete(string $uuid): JsonResponse
    {
        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_SUBMIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $declaration = $this->manager->find($uuid);
        if (!$declaration) {
            return $this->json(['error' => 'Declaration not found.'], Response::HTTP_NOT_FOUND);
        }

        /** @var \App\Entity\User|null $user */
        $user = $this->getUser();

        try {
            $this->manager->delete($declaration, $user);

            return $this->json(null, Response::HTTP_NO_CONTENT);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/declarations/bulk-delete', methods: ['POST'])]
    public function bulkDelete(Request $request): JsonResponse
    {
        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_SUBMIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];

        if (empty($ids) || !is_array($ids)) {
            return $this->json(['error' => 'Missing required field: ids (non-empty array)'], Response::HTTP_BAD_REQUEST);
        }

        /** @var \App\Entity\User|null $user */
        $user = $this->getUser();
        $deleted = 0;

        foreach ($ids as $uuid) {
            $declaration = $this->manager->find($uuid);
            if ($declaration) {
                $this->manager->delete($declaration, $user);
                $deleted++;
            }
        }

        return $this->json(['deleted' => $deleted]);
    }

    #[Route('/declarations/upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_SUBMIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        /** @var \App\Entity\User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        $file = $request->files->get('file');
        if (!$file) {
            return $this->json(['error' => 'No file uploaded.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $xmlContent = file_get_contents($file->getPathname());
            $declaration = $this->manager->createFromXml($company, $xmlContent, $user);

            return $this->json($declaration, Response::HTTP_CREATED, context: ['groups' => ['declaration:detail']]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/declarations/bulk-submit', methods: ['POST'])]
    public function bulkSubmit(Request $request): JsonResponse
    {
        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_SUBMIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];

        if (empty($ids) || !is_array($ids)) {
            return $this->json(['error' => 'Missing required field: ids (array of UUIDs).'], Response::HTTP_BAD_REQUEST);
        }

        $declarations = [];
        foreach ($ids as $id) {
            $declaration = $this->manager->find($id);
            if ($declaration) {
                $declarations[] = $declaration;
            }
        }

        $count = $this->manager->bulkSubmit($declarations);

        return $this->json(['submitted' => $count]);
    }

    #[Route('/declarations/{uuid}/recalculate', methods: ['POST'])]
    public function recalculate(string $uuid): JsonResponse
    {
        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_SUBMIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $declaration = $this->manager->find($uuid);
        if (!$declaration) {
            return $this->json(['error' => 'Declaration not found.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $declaration = $this->manager->recalculate($declaration);

            return $this->json($declaration, context: ['groups' => ['declaration:detail']]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/declarations/{uuid}/validate', methods: ['POST'])]
    public function validateDeclaration(string $uuid): JsonResponse
    {
        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_SUBMIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $declaration = $this->manager->find($uuid);
        if (!$declaration) {
            return $this->json(['error' => 'Declaration not found.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $declaration = $this->manager->validate($declaration);

            return $this->json($declaration, context: ['groups' => ['declaration:detail']]);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/declarations/{uuid}/submit', methods: ['POST'])]
    public function submitDeclaration(string $uuid): JsonResponse
    {
        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_SUBMIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $declaration = $this->manager->find($uuid);
        if (!$declaration) {
            return $this->json(['error' => 'Declaration not found.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->manager->submit($declaration);

            return $this->json($declaration, context: ['groups' => ['declaration:detail']]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/declarations/{uuid}/xml', methods: ['GET'])]
    public function downloadXml(string $uuid): Response
    {
        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $declaration = $this->manager->find($uuid);
        if (!$declaration) {
            return $this->json(['error' => 'Declaration not found.'], Response::HTTP_NOT_FOUND);
        }

        // If XML was already stored, serve from storage
        if ($declaration->getXmlPath() && $this->defaultStorage->fileExists($declaration->getXmlPath())) {
            $content = $this->defaultStorage->read($declaration->getXmlPath());
        } else {
            // Generate on the fly
            try {
                $content = $this->manager->generateXml($declaration);
            } catch (\InvalidArgumentException $e) {
                return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
            }
        }

        $filename = sprintf('%s_%d_%02d.xml', $declaration->getType()->value, $declaration->getYear(), $declaration->getMonth());

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }

    #[Route('/declarations/{uuid}/recipisa', methods: ['GET'])]
    public function downloadRecipisa(string $uuid): Response
    {
        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $declaration = $this->manager->find($uuid);
        if (!$declaration) {
            return $this->json(['error' => 'Declaration not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$declaration->getRecipisaPath() || !$this->defaultStorage->fileExists($declaration->getRecipisaPath())) {
            return $this->json(['error' => 'Recipisa not available.'], Response::HTTP_NOT_FOUND);
        }

        $content = $this->defaultStorage->read($declaration->getRecipisaPath());
        $filename = sprintf('%s_%d_%02d_recipisa.pdf', $declaration->getType()->value, $declaration->getYear(), $declaration->getMonth());

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }

    #[Route('/declarations/sync', methods: ['POST'])]
    public function sync(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_SUBMIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $year = $data['year'] ?? (int) date('Y');

        $this->manager->syncFromAnaf($company, (int) $year);

        return $this->json(['message' => 'Sync started.'], Response::HTTP_ACCEPTED);
    }

    #[Route('/declarations/refresh-statuses', methods: ['POST'])]
    public function refreshStatuses(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_SUBMIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $this->manager->refreshStatuses($company);

        return $this->json(['message' => 'Status refresh started.'], Response::HTTP_ACCEPTED);
    }

    #[Route('/declarations/{uuid}/prepare', methods: ['GET'])]
    public function prepare(string $uuid, Request $request): JsonResponse
    {
        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_SUBMIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $declaration = $this->manager->find($uuid);
        if (!$declaration) {
            return $this->json(['error' => 'Declaration not found.'], Response::HTTP_NOT_FOUND);
        }

        $company = $declaration->getCompany();
        $operation = $request->query->get('operation', 'submit');

        try {
            // Resolve ANAF Bearer token
            $anafToken = $this->anafTokenResolver->resolveEntity($company);
            if ($anafToken === null) {
                return $this->json(['error' => 'No valid ANAF token available for this company.'], Response::HTTP_BAD_REQUEST);
            }

            $cif = (string) $company->getCif();
            $type = strtoupper($declaration->getType()->value);
            $baseUrl = 'https://webserviced.anaf.ro/SPVWS2/rest';

            if ($operation === 'submit') {
                $xml = $this->manager->generateXml($declaration);

                // Store XML
                $xmlPath = sprintf(
                    'declarations/%s/%s/%s.xml',
                    $company->getId(),
                    $declaration->getType()->value,
                    $declaration->getId()
                );
                $this->defaultStorage->write($xmlPath, $xml);
                $declaration->setXmlPath($xmlPath);
                $this->entityManager->flush();

                return $this->json([
                    'xml' => $xml,
                    'anafUrl' => $baseUrl . '/cerere?tip=' . $type . '&cui=' . $cif,
                    'anafToken' => $anafToken->getToken(),
                    'declarationType' => $type,
                    'cif' => $cif,
                ]);
            } elseif ($operation === 'listMessages') {
                return $this->json([
                    'anafUrl' => $baseUrl . '/listaMesaje?zile=60',
                    'anafToken' => $anafToken->getToken(),
                    'declarationType' => $type,
                    'cif' => $cif,
                ]);
            } elseif ($operation === 'download') {
                $downloadId = $request->query->get('downloadId');
                if (!$downloadId) {
                    return $this->json(['error' => 'Missing downloadId parameter.'], Response::HTTP_BAD_REQUEST);
                }

                return $this->json([
                    'anafUrl' => $baseUrl . '/descarcare?id=' . $downloadId,
                    'anafToken' => $anafToken->getToken(),
                    'declarationType' => $type,
                    'cif' => $cif,
                ]);
            }

            return $this->json(['error' => 'Invalid operation. Use: submit, listMessages, or download.'], Response::HTTP_BAD_REQUEST);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/declarations/batch-prepare', methods: ['POST'])]
    public function batchPrepare(Request $request): JsonResponse
    {
        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_SUBMIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];

        if (empty($ids) || !is_array($ids)) {
            return $this->json(['error' => 'Missing required field: ids (array of UUIDs).'], Response::HTTP_BAD_REQUEST);
        }

        $items = [];
        $errors = [];
        $resolvedToken = null;
        $resolvedCompany = null;

        foreach ($ids as $id) {
            $declaration = $this->manager->find($id);
            if (!$declaration) {
                $errors[] = ['declarationId' => $id, 'error' => 'Declaration not found.'];
                continue;
            }

            $company = $declaration->getCompany();

            // All declarations must belong to same company
            if ($resolvedCompany === null) {
                $resolvedCompany = $company;
                try {
                    $resolvedToken = $this->anafTokenResolver->resolveEntity($company);
                    if ($resolvedToken === null) {
                        return $this->json(['error' => 'No valid ANAF token available for this company.'], Response::HTTP_BAD_REQUEST);
                    }
                } catch (\Exception $e) {
                    return $this->json(['error' => 'Failed to resolve ANAF token: ' . $e->getMessage()], Response::HTTP_BAD_REQUEST);
                }
            } elseif ((string) $company->getId() !== (string) $resolvedCompany->getId()) {
                $errors[] = ['declarationId' => $id, 'error' => 'All declarations must belong to the same company.'];
                continue;
            }

            // Only draft/validated can be submitted
            if (!in_array($declaration->getStatus(), [DeclarationStatus::DRAFT, DeclarationStatus::VALIDATED], true)) {
                $errors[] = ['declarationId' => $id, 'error' => 'Declaration status must be draft or validated.'];
                continue;
            }

            try {
                $xml = $this->manager->generateXml($declaration);

                // Store XML
                $xmlPath = sprintf(
                    'declarations/%s/%s/%s.xml',
                    $company->getId(),
                    $declaration->getType()->value,
                    $declaration->getId()
                );
                $this->defaultStorage->write($xmlPath, $xml);
                $declaration->setXmlPath($xmlPath);

                $cif = (string) $company->getCif();
                $type = strtoupper($declaration->getType()->value);
                $baseUrl = 'https://webserviced.anaf.ro/SPVWS2/rest';

                $items[] = [
                    'declarationId' => (string) $declaration->getId(),
                    'xml' => $xml,
                    'anafUrl' => $baseUrl . '/cerere?tip=' . $type . '&cui=' . $cif,
                    'anafToken' => $resolvedToken->getToken(),
                    'declarationType' => $type,
                    'cif' => $cif,
                ];
            } catch (\Exception $e) {
                $errors[] = ['declarationId' => $id, 'error' => $e->getMessage()];
            }
        }

        $this->entityManager->flush();

        return $this->json(['items' => $items, 'errors' => $errors]);
    }

    #[Route('/declarations/batch-agent-result', methods: ['POST'])]
    public function batchAgentResult(Request $request): JsonResponse
    {
        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_SUBMIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $results = $data['results'] ?? [];

        if (empty($results) || !is_array($results)) {
            return $this->json(['error' => 'Missing required field: results (array).'], Response::HTTP_BAD_REQUEST);
        }

        $processed = 0;
        $errors = [];

        foreach ($results as $result) {
            $declarationId = $result['declarationId'] ?? null;
            if (!$declarationId) {
                $errors[] = ['declarationId' => null, 'error' => 'Missing declarationId.'];
                continue;
            }

            $declaration = $this->manager->find($declarationId);
            if (!$declaration) {
                $errors[] = ['declarationId' => $declarationId, 'error' => 'Declaration not found.'];
                continue;
            }

            $statusCode = $result['statusCode'] ?? null;
            $body = $result['body'] ?? '';
            $error = $result['error'] ?? null;

            // Agent-level error (curl failed)
            if ($error) {
                $declaration->setStatus(DeclarationStatus::ERROR);
                $declaration->setErrorMessage('Agent error: ' . substr($error, 0, 500));
                $errors[] = ['declarationId' => $declarationId, 'error' => $error];
                $this->entityManager->flush();
                continue;
            }

            // ANAF HTTP error
            if ($statusCode && $statusCode >= 400) {
                $declaration->setStatus(DeclarationStatus::ERROR);
                $declaration->setErrorMessage(sprintf('ANAF returned HTTP %d: %s', $statusCode, substr($body, 0, 500)));
                $errors[] = ['declarationId' => $declarationId, 'error' => sprintf('ANAF HTTP %d', $statusCode)];
                $this->entityManager->flush();
                continue;
            }

            // Parse ANAF response
            $parsed = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $xml = @simplexml_load_string($body);
                if ($xml !== false) {
                    $parsed = json_decode(json_encode($xml), true);
                } else {
                    $parsed = ['raw' => $body];
                }
            }

            $uploadId = $parsed['id_solicitare'] ?? $parsed['index_incarcare'] ?? $parsed['id_incarcare'] ?? null;
            if ($uploadId) {
                $declaration->setAnafUploadId((string) $uploadId);
            }

            $declaration->setStatus(DeclarationStatus::PROCESSING);
            $declaration->setMetadata(array_merge($declaration->getMetadata() ?? [], [
                'uploadResult' => $parsed,
                'submittedViaAgent' => true,
            ]));
            $this->entityManager->flush();

            if ($uploadId) {
                $this->messageBus->dispatch(
                    new CheckDeclarationStatusMessage(
                        declarationId: (string) $declaration->getId(),
                    )
                );
            }

            $processed++;
        }

        return $this->json(['processed' => $processed, 'errors' => $errors]);
    }

    #[Route('/declarations/{uuid}/agent-result', methods: ['POST'])]
    public function agentResult(string $uuid, Request $request): JsonResponse
    {
        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_SUBMIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $declaration = $this->manager->find($uuid);
        if (!$declaration) {
            return $this->json(['error' => 'Declaration not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return $this->json(['error' => 'Invalid JSON body.'], Response::HTTP_BAD_REQUEST);
        }

        $statusCode = $data['statusCode'] ?? null;
        $body = $data['body'] ?? '';

        // If the ANAF request itself failed
        if ($statusCode && $statusCode >= 400) {
            $declaration->setStatus(DeclarationStatus::ERROR);
            $declaration->setErrorMessage(sprintf('ANAF returned HTTP %d: %s', $statusCode, substr($body, 0, 500)));
            $this->entityManager->flush();

            return $this->json($declaration, context: ['groups' => ['declaration:detail']]);
        }

        // Parse ANAF response
        $parsed = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $xml = @simplexml_load_string($body);
            if ($xml !== false) {
                $parsed = json_decode(json_encode($xml), true);
            } else {
                $parsed = ['raw' => $body];
            }
        }

        $uploadId = $parsed['id_solicitare'] ?? $parsed['index_incarcare'] ?? $parsed['id_incarcare'] ?? null;
        if ($uploadId) {
            $declaration->setAnafUploadId((string) $uploadId);
        }

        $declaration->setStatus(DeclarationStatus::PROCESSING);
        $declaration->setMetadata(array_merge($declaration->getMetadata() ?? [], [
            'uploadResult' => $parsed,
            'submittedViaAgent' => true,
        ]));
        $this->entityManager->flush();

        // Dispatch status check
        if ($uploadId) {
            $this->messageBus->dispatch(
                new CheckDeclarationStatusMessage(
                    declarationId: (string) $declaration->getId(),
                )
            );
        }

        return $this->json($declaration, context: ['groups' => ['declaration:detail']]);
    }

    /**
     * Prepare a sync operation — returns the ANAF URL + token for listaMesaje.
     * The frontend proxies this through the local agent for mTLS.
     */
    #[Route('/declarations/sync-prepare', methods: ['POST'])]
    public function syncPrepare(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_SUBMIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $year = $data['year'] ?? (int) date('Y');

        $anafToken = $this->anafTokenResolver->resolveEntity($company);
        if ($anafToken === null) {
            return $this->json(['error' => 'No valid ANAF token available for this company.'], Response::HTTP_BAD_REQUEST);
        }

        $cif = (string) $company->getCif();
        $baseUrl = 'https://webserviced.anaf.ro/SPVWS2/rest';

        return $this->json([
            'anafUrl' => $baseUrl . '/listaMesaje?zile=60&cif=' . $cif,
            'anafToken' => $anafToken->getToken(),
            'year' => $year,
            'cif' => $cif,
        ]);
    }

    /**
     * Process ANAF listaMesaje response from the agent-proxied sync.
     * Creates/updates declarations and returns recipisas that need downloading.
     */
    #[Route('/declarations/sync-agent-result', methods: ['POST'])]
    public function syncAgentResult(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_SUBMIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $statusCode = $data['statusCode'] ?? 200;
        $body = $data['body'] ?? '';
        $year = $data['year'] ?? (int) date('Y');

        if ($statusCode >= 400) {
            return $this->json(['error' => sprintf('ANAF returned HTTP %d: %s', $statusCode, substr($body, 0, 500))], Response::HTTP_BAD_GATEWAY);
        }

        // Parse ANAF response
        $parsed = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $xml = @simplexml_load_string($body);
            if ($xml !== false) {
                $parsed = json_decode(json_encode($xml), true);
            } else {
                return $this->json(['error' => 'Failed to parse ANAF response.'], Response::HTTP_BAD_REQUEST);
            }
        }

        $messages = $parsed['mesaje'] ?? [];
        $stats = ['created' => 0, 'updated' => 0];
        $recipisas = [];
        $seen = []; // Track type+period combos processed in this sync to prevent duplicates
        $cif = (string) $company->getCif();
        $baseUrl = 'https://webserviced.anaf.ro/SPVWS2/rest';

        $anafToken = $this->anafTokenResolver->resolveEntity($company);
        $token = $anafToken?->getToken();

        foreach ($messages as $msg) {
            $tip = $msg['tip'] ?? '';

            if (stripos($tip, 'RECIPISA') === false && stripos($tip, 'recipisa') === false) {
                continue;
            }

            // Only process messages belonging to this company's CIF
            $msgCif = (string) ($msg['cif'] ?? '');
            if ($msgCif !== '' && $msgCif !== $cif) {
                continue;
            }

            $detalii = $msg['detalii'] ?? '';
            $parsedDetails = $this->parseRecipisaDetails($detalii);
            if ($parsedDetails === null) {
                continue;
            }

            $declType = $this->resolveDeclarationType($parsedDetails['type']);
            if ($declType === null) {
                continue;
            }

            if ($parsedDetails['year'] !== $year) {
                continue;
            }

            $month = $parsedDetails['month'] ?? 1;

            // Deduplicate: skip if we already processed this type+period in this sync
            $dedupKey = sprintf('%s_%d_%d', $declType->value, $parsedDetails['year'], $month);
            if (isset($seen[$dedupKey])) {
                // Still queue recipisa download for the existing declaration
                $downloadId = $msg['id_descarcare'] ?? $msg['id'] ?? null;
                if ($downloadId && $token && $seen[$dedupKey] !== null) {
                    $recipisas[] = [
                        'declarationId' => $seen[$dedupKey],
                        'downloadId' => (string) $downloadId,
                        'anafUrl' => $baseUrl . '/descarcare?id=' . $downloadId,
                        'anafToken' => $token,
                    ];
                }
                continue;
            }

            $existing = $this->declarationRepository->findByPeriod($company, $declType, $parsedDetails['year'], $month);

            if (empty($existing)) {
                $declaration = new \App\Entity\TaxDeclaration();
                $declaration->setCompany($company);
                $declaration->setType($declType);
                $declaration->setYear($parsedDetails['year']);
                $declaration->setMonth($month);
                $declaration->setPeriodType($declType->periodType());
                $declaration->setStatus(DeclarationStatus::ACCEPTED);
                $declaration->setMetadata([
                    'source' => 'anaf_sync',
                    'anafMessageId' => $msg['id'] ?? null,
                    'registrationNumber' => $parsedDetails['registrationNumber'] ?? null,
                    'anafCreatedAt' => $msg['data_creare'] ?? null,
                    'anafIdSolicitare' => $msg['id_solicitare'] ?? null,
                ]);

                $this->entityManager->persist($declaration);
                $this->entityManager->flush();
                $stats['created']++;
                $seen[$dedupKey] = (string) $declaration->getId();

                // Queue recipisa download
                $downloadId = $msg['id_descarcare'] ?? $msg['id'] ?? null;
                if ($downloadId && $token) {
                    $recipisas[] = [
                        'declarationId' => (string) $declaration->getId(),
                        'downloadId' => (string) $downloadId,
                        'anafUrl' => $baseUrl . '/descarcare?id=' . $downloadId,
                        'anafToken' => $token,
                    ];
                }
            } else {
                $seen[$dedupKey] = (string) $existing[0]->getId();
                foreach ($existing as $declaration) {
                    $updated = false;

                    if (in_array($declaration->getStatus(), [DeclarationStatus::SUBMITTED, DeclarationStatus::PROCESSING], true)) {
                        $declaration->setStatus(DeclarationStatus::ACCEPTED);
                        $declaration->setMetadata(array_merge($declaration->getMetadata() ?? [], [
                            'syncedFromAnaf' => true,
                            'anafMessageId' => $msg['id'] ?? null,
                            'registrationNumber' => $parsedDetails['registrationNumber'] ?? null,
                            'anafCreatedAt' => $msg['data_creare'] ?? null,
                            'anafIdSolicitare' => $msg['id_solicitare'] ?? null,
                        ]));
                        $updated = true;
                        $stats['updated']++;
                    }

                    if ($declaration->getRecipisaPath() === null) {
                        $downloadId = $msg['id_descarcare'] ?? $msg['id'] ?? null;
                        if ($downloadId && $token) {
                            $recipisas[] = [
                                'declarationId' => (string) $declaration->getId(),
                                'downloadId' => (string) $downloadId,
                                'anafUrl' => $baseUrl . '/descarcare?id=' . $downloadId,
                                'anafToken' => $token,
                            ];
                        }
                        $updated = true;
                    }

                    if ($updated) {
                        break;
                    }
                }
            }
        }

        $this->entityManager->flush();

        return $this->json([
            'stats' => $stats,
            'recipisas' => $recipisas,
        ]);
    }

    /**
     * Store a recipisa PDF downloaded via the agent proxy.
     */
    #[Route('/declarations/{uuid}/agent-recipisa', methods: ['POST'])]
    public function agentRecipisa(string $uuid, Request $request): JsonResponse
    {
        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_SUBMIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $declaration = $this->manager->find($uuid);
        if (!$declaration) {
            return $this->json(['error' => 'Declaration not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        $statusCode = $data['statusCode'] ?? 200;
        $body = $data['body'] ?? '';

        if ($statusCode >= 400) {
            return $this->json(['error' => 'Failed to download recipisa from ANAF.'], Response::HTTP_BAD_GATEWAY);
        }

        $company = $declaration->getCompany();
        $recipisaPath = sprintf(
            'declarations/%s/%s/%s_recipisa.pdf',
            $company->getId(),
            $declaration->getType()->value,
            $declaration->getId()
        );

        // Body comes as text from the agent — it may be base64 encoded or raw
        $this->defaultStorage->write($recipisaPath, $body);
        $declaration->setRecipisaPath($recipisaPath);
        $this->entityManager->flush();

        return $this->json(['message' => 'Recipisa stored.']);
    }

    /**
     * Prepare a refresh-statuses operation — returns ANAF URL + token for listaMesaje.
     */
    #[Route('/declarations/refresh-prepare', methods: ['POST'])]
    public function refreshPrepare(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_SUBMIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $anafToken = $this->anafTokenResolver->resolveEntity($company);
        if ($anafToken === null) {
            return $this->json(['error' => 'No valid ANAF token available for this company.'], Response::HTTP_BAD_REQUEST);
        }

        $cif = (string) $company->getCif();
        $baseUrl = 'https://webserviced.anaf.ro/SPVWS2/rest';

        return $this->json([
            'anafUrl' => $baseUrl . '/listaMesaje?zile=60&cif=' . $cif,
            'anafToken' => $anafToken->getToken(),
            'cif' => $cif,
        ]);
    }

    /**
     * Process ANAF listaMesaje response for status refresh.
     * Updates in-flight declarations and returns recipisas to download.
     */
    #[Route('/declarations/refresh-agent-result', methods: ['POST'])]
    public function refreshAgentResult(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_SUBMIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $statusCode = $data['statusCode'] ?? 200;
        $body = $data['body'] ?? '';

        if ($statusCode >= 400) {
            return $this->json(['error' => sprintf('ANAF returned HTTP %d: %s', $statusCode, substr($body, 0, 500))], Response::HTTP_BAD_GATEWAY);
        }

        $parsed = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $xml = @simplexml_load_string($body);
            if ($xml !== false) {
                $parsed = json_decode(json_encode($xml), true);
            } else {
                return $this->json(['error' => 'Failed to parse ANAF response.'], Response::HTTP_BAD_REQUEST);
            }
        }

        $messages = $parsed['mesaje'] ?? [];
        $stats = ['accepted' => 0, 'rejected' => 0];
        $recipisas = [];

        $anafToken = $this->anafTokenResolver->resolveEntity($company);
        $token = $anafToken?->getToken();
        $baseUrl = 'https://webserviced.anaf.ro/SPVWS2/rest';

        // Index messages by id_solicitare
        $messagesByUploadId = [];
        foreach ($messages as $msg) {
            $idSolicitare = $msg['id_solicitare'] ?? null;
            if ($idSolicitare !== null) {
                $messagesByUploadId[(string) $idSolicitare] = $msg;
            }
        }

        // Find in-flight declarations
        $inFlight = $this->declarationRepository->findByCompanyAndStatuses($company, [
            DeclarationStatus::SUBMITTED,
            DeclarationStatus::PROCESSING,
        ]);

        foreach ($inFlight as $declaration) {
            $uploadId = $declaration->getAnafUploadId();
            if ($uploadId === null) {
                continue;
            }

            $msg = $messagesByUploadId[$uploadId] ?? null;
            if ($msg === null) {
                continue;
            }

            $stare = $msg['stare'] ?? $msg['Stare'] ?? null;

            if ($stare === 'ok' || $stare === '1') {
                $declaration->setStatus(DeclarationStatus::ACCEPTED);
                $declaration->setMetadata(array_merge($declaration->getMetadata() ?? [], [
                    'statusRefresh' => $msg,
                ]));
                $stats['accepted']++;

                $downloadId = $msg['id_descarcare'] ?? $msg['id'] ?? null;
                if ($downloadId && $declaration->getRecipisaPath() === null && $token) {
                    $recipisas[] = [
                        'declarationId' => (string) $declaration->getId(),
                        'downloadId' => (string) $downloadId,
                        'anafUrl' => $baseUrl . '/descarcare?id=' . $downloadId,
                        'anafToken' => $token,
                    ];
                }
            } elseif ($stare === 'nok' || $stare === '2') {
                $errorMessage = $msg['Errors'] ?? $msg['eroare'] ?? 'Declaration rejected by ANAF.';
                if (is_array($errorMessage)) {
                    $errorMessage = implode('; ', $errorMessage);
                }

                $declaration->setStatus(DeclarationStatus::REJECTED);
                $declaration->setErrorMessage($errorMessage);
                $declaration->setMetadata(array_merge($declaration->getMetadata() ?? [], [
                    'statusRefresh' => $msg,
                ]));
                $stats['rejected']++;
            }
        }

        $this->entityManager->flush();

        return $this->json([
            'stats' => $stats,
            'recipisas' => $recipisas,
        ]);
    }

    private function resolveCompany(Request $request): ?\App\Entity\Company
    {
        return $this->organizationContext->resolveCompany($request);
    }

    private function parseRecipisaDetails(string $detalii): ?array
    {
        $result = [];

        // Match declaration types: D300, D390, S1005, etc.
        if (preg_match('/tip\s+([DS]\d+)/i', $detalii, $m)) {
            $result['type'] = strtoupper($m[1]);
        } else {
            return null;
        }

        if (preg_match('/perioada\s+raportare\s+(\d{1,2})\.(\d{4})/i', $detalii, $m)) {
            $result['month'] = (int) $m[1];
            $result['year'] = (int) $m[2];
        } elseif (preg_match('/perioada\s+raportare\s+(\d{4})/i', $detalii, $m)) {
            $result['year'] = (int) $m[1];
            $result['month'] = 1;
        } else {
            return null;
        }

        // Extract numar_inregistrare (e.g. INTERNT-1045624064-2026/26-01-2026)
        if (preg_match('/numar_inregistrare\s+([^,]+)/i', $detalii, $m)) {
            $result['registrationNumber'] = trim($m[1]);
        }

        // Extract CIF from detalii
        if (preg_match('/CIF\s+(\d+)/i', $detalii, $m)) {
            $result['cif'] = $m[1];
        }

        return $result;
    }

    private function resolveDeclarationType(string $anafType): ?\App\Enum\DeclarationType
    {
        $normalized = strtolower($anafType);

        return \App\Enum\DeclarationType::tryFrom($normalized);
    }
}
