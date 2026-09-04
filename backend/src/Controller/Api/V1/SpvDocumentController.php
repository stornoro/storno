<?php

namespace App\Controller\Api\V1;

use App\Entity\SpvDocument;
use App\Enum\SpvDocumentCategory;
use App\Enum\SpvDocumentSeverity;
use App\Repository\SpvDocumentRepository;
use App\Security\Permission;
use App\Security\OrganizationContext;
use App\Service\Spv\SpvDocumentIngestionService;
use App\Constants\Pagination;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * SPV inbox archive: every ANAF message (somatii, decizii, notificari,
 * recipise...) for a company, classified, with the PDF archived.
 *
 * Listing and downloading from ANAF require the qualified certificate, so the
 * browser drives the local storno-agent: sync-prepare → agent GET listaMesaje
 * → sync-agent-result (ingest, returns PDFs to fetch) → agent GET descarcare
 * → agent-document (store).
 */
#[Route('/api/v1/spv')]
class SpvDocumentController extends AbstractController
{
    private const MAX_DAYS = 60;

    public function __construct(
        private readonly SpvDocumentRepository $repository,
        private readonly SpvDocumentIngestionService $ingestion,
        private readonly OrganizationContext $organizationContext,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/documents', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $q = $request->query;
        $filters = [
            'category' => $q->get('category'),
            'severity' => $q->get('severity'),
            'unread' => $q->getBoolean('unread'),
            'search' => $q->get('search'),
            'from' => $this->parseDate($q->get('from')),
            'to' => $this->parseDate($q->get('to'), endOfDay: true),
        ];
        $page = max(1, $q->getInt('page', 1));
        $limit = Pagination::clamp($q->getInt('limit', Pagination::DEFAULT_LIMIT));

        $result = $this->repository->findByCompanyPaginated($company, $filters, $page, $limit);

        return $this->json($result, context: ['groups' => ['spv_document:list']]);
    }

    #[Route('/documents/stats', methods: ['GET'])]
    public function stats(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $stats = $this->repository->statsForCompany($company);
        $stats['categories'] = array_map(
            static fn (SpvDocumentCategory $c) => ['value' => $c->value, 'label' => $c->label()],
            SpvDocumentCategory::cases(),
        );
        $stats['severities'] = array_map(static fn (SpvDocumentSeverity $s) => $s->value, SpvDocumentSeverity::cases());

        return $this->json($stats);
    }

    #[Route('/documents/read-all', methods: ['POST'])]
    public function readAll(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $updated = $this->entityManager->createQuery(
            'UPDATE App\Entity\SpvDocument d SET d.readAt = :now WHERE d.company = :company AND d.readAt IS NULL'
        )->setParameter('now', new \DateTimeImmutable())->setParameter('company', $company)->execute();

        return $this->json(['updated' => $updated]);
    }

    #[Route('/documents/{uuid}', methods: ['GET'])]
    public function show(string $uuid, Request $request): JsonResponse
    {
        $doc = $this->findOwned($uuid, Permission::DECLARATION_VIEW);
        if ($doc instanceof JsonResponse) {
            return $doc;
        }

        return $this->json($doc, context: ['groups' => ['spv_document:detail']]);
    }

    #[Route('/documents/{uuid}/read', methods: ['PATCH'])]
    public function markRead(string $uuid): JsonResponse
    {
        $doc = $this->findOwned($uuid, Permission::DECLARATION_VIEW);
        if ($doc instanceof JsonResponse) {
            return $doc;
        }
        if ($doc->getReadAt() === null) {
            $doc->setReadAt(new \DateTimeImmutable());
            $this->entityManager->flush();
        }

        return $this->json($doc, context: ['groups' => ['spv_document:detail']]);
    }

    #[Route('/documents/{uuid}/download', methods: ['GET'])]
    public function download(string $uuid): Response
    {
        $doc = $this->findOwned($uuid, Permission::DECLARATION_VIEW);
        if ($doc instanceof JsonResponse) {
            return $doc;
        }
        if (!$doc->getHasPdf()) {
            return $this->json([
                'error' => $doc->getPurgedAt() ? 'Fisierul a fost sters conform politicii de retentie.' : 'Fisierul nu a fost inca descarcat din SPV.',
                'code' => $doc->getPurgedAt() ? 'SPV_FILE_PURGED' : 'SPV_FILE_PENDING',
            ], Response::HTTP_NOT_FOUND);
        }

        $storage = $this->ingestion->storageFor($doc);
        $path = (string) $doc->getPdfPath();
        if (!$storage->fileExists($path)) {
            return $this->json(['error' => 'Fisierul lipseste din stocare.', 'code' => 'SPV_FILE_MISSING'], Response::HTTP_NOT_FOUND);
        }

        $content = $storage->read($path);
        $isPdf = str_ends_with($path, '.pdf');
        $fileName = $doc->getFileName() ?? basename($path);

        if ($doc->getReadAt() === null) {
            $doc->setReadAt(new \DateTimeImmutable());
            $this->entityManager->flush();
        }

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => $isPdf ? 'application/pdf' : 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $fileName, 'document.' . ($isPdf ? 'pdf' : 'bin')),
            'Content-Length' => (string) strlen($content),
        ]);
    }

    /**
     * Step 1 of an agent sync: where the agent should GET the inbox listing.
     */
    #[Route('/sync-prepare', methods: ['POST'])]
    public function syncPrepare(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_SUBMIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true) ?: [];
        $days = min(self::MAX_DAYS, max(1, (int) ($data['days'] ?? self::MAX_DAYS)));
        $cif = (string) $company->getCif();

        return $this->json([
            'anafUrl' => sprintf('%s/listaMesaje?zile=%d&cif=%s', SpvDocumentIngestionService::ANAF_BASE_URL, $days, $cif),
            'cif' => $cif,
            'days' => $days,
            'pendingDownloads' => $this->ingestion->pendingDownloads($company),
        ]);
    }

    /**
     * Step 2: the agent relays ANAF's listaMesaje answer; we archive every
     * message and return the PDFs still to be fetched.
     */
    #[Route('/sync-agent-result', methods: ['POST'])]
    public function syncAgentResult(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_SUBMIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true) ?: [];
        $statusCode = (int) ($data['statusCode'] ?? 200);
        $body = $data['body'] ?? '';

        if ($statusCode >= 400) {
            return $this->json([
                'error' => sprintf('ANAF returned HTTP %d', $statusCode),
                'hint' => is_string($body) ? mb_substr(strip_tags($body), 0, 300) : null,
            ], Response::HTTP_BAD_GATEWAY);
        }

        $parsed = $this->parseAnafBody($body);
        if ($parsed === null) {
            return $this->json([
                'error' => 'Raspunsul ANAF nu a putut fi interpretat. Sesiunea SPV a expirat sau certificatul nu are drepturi pe acest CUI.',
                'code' => 'SPV_UNPARSEABLE',
                'hint' => is_string($body) ? mb_substr(strip_tags($body), 0, 300) : null,
            ], Response::HTTP_BAD_GATEWAY);
        }

        if (isset($parsed['eroare']) && empty($parsed['mesaje'])) {
            return $this->json([
                'error' => (string) $parsed['eroare'],
                'code' => 'SPV_ERROR',
            ], Response::HTTP_BAD_GATEWAY);
        }

        $result = $this->ingestion->ingest($company, is_array($parsed['mesaje'] ?? null) ? $parsed['mesaje'] : []);

        return $this->json([
            'stats' => ['created' => $result['created'], 'skipped' => $result['skipped'], 'received' => count($parsed['mesaje'] ?? [])],
            'documents' => $result['documents'],
        ]);
    }

    /**
     * Step 3: the agent uploads a PDF it fetched from descarcare.
     */
    #[Route('/documents/{uuid}/agent-document', methods: ['POST'])]
    public function agentDocument(string $uuid, Request $request): JsonResponse
    {
        $doc = $this->findOwned($uuid, Permission::DECLARATION_SUBMIT);
        if ($doc instanceof JsonResponse) {
            return $doc;
        }

        $data = json_decode($request->getContent(), true) ?: [];
        $statusCode = (int) ($data['statusCode'] ?? 200);
        $body = $data['body'] ?? '';
        if ($statusCode >= 400 || $body === '' || $body === null) {
            $doc->setDownloadError(sprintf('ANAF HTTP %d', $statusCode));
            $this->entityManager->flush();
            return $this->json(['error' => 'Failed to download document from ANAF.'], Response::HTTP_BAD_GATEWAY);
        }

        // Body may arrive as byte array, base64 or raw binary string (same as agent-recipisa)
        if (is_array($body)) {
            $body = implode('', array_map('chr', $body));
        } elseif (($data['bodyEncoding'] ?? null) === 'base64') {
            $body = base64_decode($body) ?: $body;
        }

        try {
            $this->ingestion->storeDocumentFile($doc, (string) $body);
        } catch (\App\Service\Spv\SpvNotADocumentException $e) {
            return $this->json(['error' => $e->getMessage(), 'code' => 'SPV_NOT_A_DOCUMENT'], Response::HTTP_BAD_GATEWAY);
        } catch (\Throwable $e) {
            $doc->setDownloadError('Stocare esuata: ' . mb_substr($e->getMessage(), 0, 300));
            $this->entityManager->flush();
            return $this->json(['error' => 'Fisierul nu a putut fi salvat.', 'code' => 'SPV_STORE_FAILED'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json($doc, context: ['groups' => ['spv_document:detail']]);
    }

    private function findOwned(string $uuid, string $permission): SpvDocument|JsonResponse
    {
        if (!$this->organizationContext->hasPermission($permission)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }
        $doc = $this->repository->find($uuid);
        if (!$doc || !$this->organizationContext->ownsCompany($doc->getCompany())) {
            return $this->json(['error' => 'Document not found.'], Response::HTTP_NOT_FOUND);
        }
        return $doc;
    }

    /** @return array<string, mixed>|null */
    private function parseAnafBody(mixed $body): ?array
    {
        if (is_array($body)) {
            return $body;
        }
        if (!is_string($body) || trim($body) === '') {
            return null;
        }
        // double-encoded JSON string
        if ($body[0] === '"') {
            $unwrapped = json_decode($body, true);
            if (is_string($unwrapped)) {
                $body = $unwrapped;
            }
        }
        $parsed = json_decode($body, true);
        if (is_array($parsed)) {
            return $parsed;
        }
        return null;
    }

    private function parseDate(?string $raw, bool $endOfDay = false): ?\DateTimeImmutable
    {
        if (!$raw) {
            return null;
        }
        try {
            $d = new \DateTimeImmutable($raw);
            return $endOfDay ? $d->setTime(23, 59, 59) : $d;
        } catch (\Exception) {
            return null;
        }
    }
}
