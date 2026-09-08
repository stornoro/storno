<?php

namespace App\Controller\Api\V1;

use App\Entity\Dosar;
use App\Entity\DosarFile;
use App\Entity\SpvDocument;
use App\Entity\SpvRequest;
use App\Entity\TaxDeclaration;
use App\Entity\User;
use App\Enum\DeclarationType;
use App\Manager\TaxDeclarationManager;
use App\Repository\DosarRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Service\Document\LegalDocumentService;
use App\Service\Declaration\Forms\DeclarationFormRegistry;
use App\Service\Dosar\C168RegistryParser;
use App\Service\Dosar\DosarService;
use App\Service\Spv\SpvDocumentIngestionService;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dosare: the case files that group declarations, SPV requests and ANAF messages
 * around a rental contract, a year's Declarația unică, the periodic returns or the
 * company's fiscal standing.
 *
 *   GET    /api/v1/dosare                 list (?type=&status=)
 *   GET    /api/v1/dosare/actions         "De rezolvat" / "În lucru la ANAF" / "Răspunsuri noi"
 *   POST   /api/v1/dosare                 create
 *   GET    /api/v1/dosare/{uuid}          detail: children + timeline
 *   PATCH  /api/v1/dosare/{uuid}          update (title, subject, status, nextStep, deadline, notes)
 *   DELETE /api/v1/dosare/{uuid}          delete (children are unlinked, never deleted)
 *   POST   /api/v1/dosare/{uuid}/attach   {declarationId | requestId | documentId}
 *   POST   /api/v1/dosare/{uuid}/detach   same body
 *   POST   /api/v1/dosare/annual-return   ensure the Declarația unică dosar for the next 25 May
 *   GET    /api/v1/dosare/{uuid}/d212-prefill   D212 rent-scenario input from the rental dosare
 *   POST   /api/v1/dosare/{uuid}/d212     create the D212 draft in the dosar from that input
 */
#[Route('/api/v1/dosare')]
class DosarController extends AbstractController
{
    public function __construct(
        private readonly OrganizationContext $organizationContext,
        private readonly EntityManagerInterface $em,
        private readonly DosarRepository $repository,
        private readonly DosarService $service,
        private readonly TaxDeclarationManager $declarations,
        private readonly LegalDocumentService $documents,
        private readonly DeclarationFormRegistry $forms,
        private readonly FilesystemOperator $defaultStorage,
        private readonly C168RegistryParser $registryParser,
        private readonly SpvDocumentIngestionService $ingestion,
    ) {}

    #[Route('', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }
        $type = $request->query->get('type');
        $status = $request->query->get('status');
        $items = $this->repository->findForCompany($company, is_string($type) ? $type : null, is_string($status) ? $status : null);
        $counts = [];
        foreach ($items as $d) {
            $counts[$d->getId()->toRfc4122()] = $this->counts($d);
        }

        return $this->json(['data' => $items, 'counts' => $counts, 'total' => count($items), 'types' => Dosar::TYPES, 'statuses' => Dosar::STATUSES], context: ['groups' => ['dosar:list']]);
    }

    #[Route('/actions', methods: ['GET'])]
    public function actions(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json($this->service->actions($company));
    }

    /** Rental portfolio: properties, active contracts, monthly rent by currency, expected vs declared rent per year. */
    #[Route('/stats', methods: ['GET'])]
    public function stats(Request $request): Response
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $stats = $this->service->stats($company);
        if ($request->query->get('format') === 'csv') {
            $out = fopen('php://temp', 'w+');
            fputcsv($out, ['dosar', 'adresa', 'chirias', 'chirie', 'moneda', 'de_la', 'pana_la', 'activ', 'expira_in_zile', 'stare', 'declaratii']);
            foreach ($stats['properties'] as $p) {
                fputcsv($out, [$p['title'], $p['adresa'], $p['chirias'], $p['chirie'], $p['moneda'], $p['deLa'], $p['panaLa'], $p['active'] ? 'da' : 'nu', $p['expiresInDays'], $p['status'], $p['declarations']]);
            }
            rewind($out);
            $csv = "\xEF\xBB\xBF" . stream_get_contents($out);

            return new Response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="portofoliu-inchirieri.csv"']);
        }

        return $this->json($stats);
    }

    /**
     * Contracts from ANAF's registry extract (the answer to a C168 request), matched against
     * the existing dosare. GET reads the newest extract archived in the SPV inbox (or
     * ?documentId=); POST accepts the extract PDF as multipart "file" (downloaded by hand).
     */
    #[Route('/registry-proposals', methods: ['GET', 'POST'])]
    public function registryProposals(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }
        $pdf = null;
        $source = null;
        $upload = $request->files->get('file');
        if ($upload !== null && $upload->isValid()) {
            $pdf = (string) file_get_contents($upload->getPathname());
            $source = ['kind' => 'upload', 'name' => $upload->getClientOriginalName()];
        } else {
            $docs = $this->em->getRepository(SpvDocument::class)->findBy(['company' => $company], ['anafCreatedAt' => 'DESC'], 300);
            $wanted = $request->query->get('documentId');
            foreach ($docs as $doc) {
                if ($wanted !== null && $doc->getId()?->toRfc4122() !== $wanted) {
                    continue;
                }
                if ($wanted === null && !str_contains(mb_strtolower((string) $doc->getDetails()), 'registrul contractelor de locatiune')) {
                    continue;
                }
                if (!$doc->getHasPdf() || $doc->getPdfPath() === null) {
                    continue;
                }
                $storage = $this->ingestion->storageFor($doc);
                if ($storage->fileExists((string) $doc->getPdfPath())) {
                    $pdf = $storage->read((string) $doc->getPdfPath());
                    $source = ['kind' => 'spv', 'documentId' => $doc->getId()?->toRfc4122(), 'date' => $doc->getAnafCreatedAt()?->format(DATE_ATOM)];
                    break;
                }
            }
        }
        if ($pdf === null) {
            return $this->json(['error' => 'Niciun extras de registru găsit. Solicită „Registrul contractelor de locațiune (C168)” din SPV sau încarcă PDF-ul primit.', 'code' => 'NO_REGISTRY'], Response::HTTP_NOT_FOUND);
        }
        try {
            $parsed = $this->registryParser->parse($pdf);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'PDF-ul nu a putut fi citit ca extras de registru.', 'code' => 'PARSE_FAILED'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($parsed['rows'] === []) {
            return $this->json(['error' => 'În PDF nu am găsit rânduri de registru (formatul ANAF așteptat: „Registrul contractelor de locațiune”).', 'code' => 'NO_ROWS'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['source' => $source] + $this->service->registryProposals($company, $parsed));
    }

    /** Create dosare for the registry contracts the user ticked (body: {contracts: [...]} as returned by registry-proposals). */
    #[Route('/registry-import', methods: ['POST'])]
    public function registryImport(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_SUBMIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }
        $body = json_decode($request->getContent(), true);
        $contracts = is_array($body['contracts'] ?? null) ? $body['contracts'] : [];
        if ($contracts === []) {
            return $this->json(['error' => 'Trimite contracts[] din registry-proposals.', 'code' => 'INVALID_INPUT'], Response::HTTP_BAD_REQUEST);
        }
        $user = $this->getUser();
        $created = $this->service->importRegistryContracts($company, $contracts, $user instanceof User ? $user : null);

        return $this->json(['created' => count($created), 'dosare' => $created], Response::HTTP_CREATED, context: ['groups' => ['dosar:list']]);
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_SUBMIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }
        $input = json_decode($request->getContent(), true);
        if (!is_array($input)) {
            return $this->json(['error' => 'Corpul cererii trebuie să fie JSON.', 'code' => 'INVALID_JSON'], Response::HTTP_BAD_REQUEST);
        }
        $user = $this->getUser();
        try {
            $dosar = $this->service->create($company, $input, $user instanceof User ? $user : null);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage(), 'code' => 'VALIDATION_FAILED'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($this->detail($dosar), Response::HTTP_CREATED, context: ['groups' => ['dosar:detail', 'declaration:list', 'spv_request:list', 'spv_document:list', 'dosar_file:list']]);
    }

    #[Route('/annual-return', methods: ['POST'])]
    public function annualReturn(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_SUBMIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }
        $body = json_decode($request->getContent(), true) ?: [];
        $year = (int) ($body['an'] ?? $this->service->nextFilingYear());
        $user = $this->getUser();
        $dosar = $this->service->ensureAnnualReturnDosar($company, $year, $user instanceof User ? $user : null);

        return $this->json($this->detail($dosar), context: ['groups' => ['dosar:detail', 'declaration:list', 'spv_request:list', 'spv_document:list', 'dosar_file:list']]);
    }

    #[Route('/{uuid}', methods: ['GET'])]
    public function show(string $uuid): JsonResponse
    {
        $dosar = $this->findOwned($uuid, Permission::DECLARATION_VIEW);
        if ($dosar instanceof JsonResponse) {
            return $dosar;
        }

        return $this->json($this->detail($dosar), context: ['groups' => ['dosar:detail', 'declaration:list', 'spv_request:list', 'spv_document:list', 'dosar_file:list']]);
    }

    #[Route('/{uuid}', methods: ['PATCH'])]
    public function update(string $uuid, Request $request): JsonResponse
    {
        $dosar = $this->findOwned($uuid, Permission::DECLARATION_SUBMIT);
        if ($dosar instanceof JsonResponse) {
            return $dosar;
        }
        $input = json_decode($request->getContent(), true);
        if (!is_array($input)) {
            return $this->json(['error' => 'Corpul cererii trebuie să fie JSON.', 'code' => 'INVALID_JSON'], Response::HTTP_BAD_REQUEST);
        }
        try {
            $this->service->apply($dosar, $input);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage(), 'code' => 'VALIDATION_FAILED'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->em->flush();

        return $this->json($this->detail($dosar), context: ['groups' => ['dosar:detail', 'declaration:list', 'spv_request:list', 'spv_document:list', 'dosar_file:list']]);
    }

    #[Route('/{uuid}', methods: ['DELETE'])]
    public function delete(string $uuid): JsonResponse
    {
        $dosar = $this->findOwned($uuid, Permission::DECLARATION_SUBMIT);
        if ($dosar instanceof JsonResponse) {
            return $dosar;
        }
        // children keep existing: the FK is ON DELETE SET NULL
        $this->em->remove($dosar);
        $this->em->flush();

        return $this->json(['deleted' => true]);
    }

    #[Route('/{uuid}/attach', methods: ['POST'])]
    public function attach(string $uuid, Request $request): JsonResponse
    {
        return $this->link($uuid, $request, true);
    }

    #[Route('/{uuid}/detach', methods: ['POST'])]
    public function detach(string $uuid, Request $request): JsonResponse
    {
        return $this->link($uuid, $request, false);
    }

    #[Route('/{uuid}/d212-prefill', methods: ['GET'])]
    public function d212Prefill(string $uuid): JsonResponse
    {
        $dosar = $this->findOwned($uuid, Permission::DECLARATION_VIEW);
        if ($dosar instanceof JsonResponse) {
            return $dosar;
        }
        if ($dosar->getType() !== Dosar::TYPE_ANNUAL_RETURN) {
            return $this->json(['error' => 'Precompletarea D212 se face dintr-un dosar „Declarația unică”.', 'code' => 'WRONG_TYPE'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $year = (int) ($dosar->getSubject()['an'] ?? $this->service->nextFilingYear());

        return $this->json($this->service->prefillD212($dosar->getCompany(), $year) + ['an' => $year]);
    }

    /** Create the D212 draft (rent scenario) in this dosar; body {input} overrides the prefill (reviewed by the user). */
    #[Route('/{uuid}/d212', methods: ['POST'])]
    public function createD212(string $uuid, Request $request): JsonResponse
    {
        $dosar = $this->findOwned($uuid, Permission::DECLARATION_SUBMIT);
        if ($dosar instanceof JsonResponse) {
            return $dosar;
        }
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }
        $year = (int) ($dosar->getSubject()['an'] ?? $this->service->nextFilingYear());
        $body = json_decode($request->getContent(), true) ?: [];
        $input = is_array($body['input'] ?? null) ? $body['input'] : $this->service->prefillD212($dosar->getCompany(), $year)['input'];
        $input['an'] = $year;
        try {
            $declaration = $this->declarations->create($dosar->getCompany(), ['type' => DeclarationType::D212->value, 'year' => $year, 'month' => 12, 'periodType' => 'annual'], $user);
            $declaration->setData(['input' => $input]);
            $this->service->attachDeclaration($dosar, $declaration);
            $dosar->setNextStep('Verifică D212, validează și depune prin agent.');
            $this->em->flush();
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage(), 'code' => 'VALIDATION_FAILED'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($declaration, Response::HTTP_CREATED, context: ['groups' => ['declaration:detail']]);
    }

    /**
     * Generate a legal document from the dosar (convenție de încetare, declarație pe propria
     * răspundere): GET returns the prefilled fields to review, POST renders the PDF with the
     * reviewed fields (body = overrides). ?format=pdf streams the file.
     */
    #[Route('/{uuid}/document/{type}', methods: ['GET', 'POST'])]
    public function document(string $uuid, string $type, Request $request): Response
    {
        $dosar = $this->findOwned($uuid, Permission::DECLARATION_VIEW);
        if ($dosar instanceof JsonResponse) {
            return $dosar;
        }
        if (!isset(LegalDocumentService::TYPES[$type])) {
            return $this->json(['error' => 'Tip de document necunoscut.', 'code' => 'UNKNOWN_TYPE', 'types' => $this->documents->catalog()], Response::HTTP_NOT_FOUND);
        }
        $overrides = $request->isMethod('POST') ? (json_decode($request->getContent(), true) ?: []) : [];
        $fields = $this->service->documentInput($dosar, $type, is_array($overrides) ? $overrides : []);
        if ($request->isMethod('GET')) {
            return $this->json(['type' => $type, 'title' => LegalDocumentService::TYPES[$type]['title'], 'required' => LegalDocumentService::TYPES[$type]['required'], 'fields' => $fields]);
        }
        try {
            $doc = $this->documents->render($type, $fields);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage(), 'code' => 'VALIDATION_FAILED', 'fields' => $fields], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $fileName = sprintf('%s-%s.pdf', $type, substr((string) $dosar->getId(), 0, 8));
        if ($request->query->get('format') === 'pdf') {
            return new Response($doc['pdf'], 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => sprintf('attachment; filename="%s"', $fileName)]);
        }
        if (in_array($type, ['declaratie_incetare_contract', 'conventie_incetare_inchiriere', 'notificare_incetare_inchiriere'], true)) {
            $dosar->setNextStep('Semnează documentul de încetare, încarcă-l în dosar și depune C168 încetare cu el atașat (termen 30 de zile).')->touch();
        } elseif ($type === 'act_aditional_inchiriere') {
            $dosar->setNextStep('Semnează actul adițional, încarcă-l în dosar și depune C168 modificare cu el atașat (termen 30 de zile).')->touch();
            $this->em->flush();
        }

        return $this->json(['type' => $type, 'title' => $doc['title'], 'fileName' => $fileName, 'pdfBase64' => base64_encode($doc['pdf'])]);
    }

    // ── Files ─────────────────────────────────────────────────────────

    #[Route('/{uuid}/files', methods: ['POST'])]
    public function uploadFile(string $uuid, Request $request): JsonResponse
    {
        $dosar = $this->findOwned($uuid, Permission::DECLARATION_SUBMIT);
        if ($dosar instanceof JsonResponse) {
            return $dosar;
        }
        $upload = $request->files->get('file');
        if ($upload === null || !$upload->isValid()) {
            return $this->json(['error' => 'Trimite fișierul în câmpul multipart "file".', 'code' => 'NO_FILE'], Response::HTTP_BAD_REQUEST);
        }
        if ($upload->getSize() > 10 * 1024 * 1024) {
            return $this->json(['error' => 'Fișierul depășește 10 MB.', 'code' => 'FILE_TOO_LARGE'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        $mime = (string) ($upload->getMimeType() ?? $upload->getClientMimeType());
        if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/tiff'], true)) {
            return $this->json(['error' => 'Doar PDF, JPG, PNG sau TIFF.', 'code' => 'FILE_TYPE'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $kind = (string) $request->request->get('kind', DosarFile::KIND_ALTELE);
        if (!in_array($kind, DosarFile::KINDS, true)) {
            $kind = DosarFile::KIND_ALTELE;
        }
        $file = (new DosarFile())->setDosar($dosar)->setKind($kind)->setMime($mime)->setSize((int) $upload->getSize());
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $upload->getClientOriginalName()) ?: 'document';
        $file->setName(mb_substr($upload->getClientOriginalName(), 0, 255));
        $file->setPath(sprintf('dosare/%s/%s/%s-%s', $dosar->getCompany()?->getId(), $dosar->getId(), substr((string) $file->getId(), 0, 8), $safe));
        $user = $this->getUser();
        $file->setUploadedBy($user instanceof User ? $user : null);
        $this->defaultStorage->write($file->getPath(), (string) file_get_contents($upload->getPathname()));
        $this->em->persist($file);
        $dosar->touch();
        $this->em->flush();

        return $this->json($this->detail($dosar), Response::HTTP_CREATED, context: ['groups' => ['dosar:detail', 'declaration:list', 'spv_request:list', 'spv_document:list', 'dosar_file:list']]);
    }

    #[Route('/{uuid}/files/{fileId}/download', methods: ['GET'])]
    public function downloadFile(string $uuid, string $fileId): Response
    {
        $dosar = $this->findOwned($uuid, Permission::DECLARATION_VIEW);
        if ($dosar instanceof JsonResponse) {
            return $dosar;
        }
        $file = $this->em->getRepository(DosarFile::class)->find($fileId);
        if (!$file instanceof DosarFile || $file->getDosar() !== $dosar || !$this->defaultStorage->fileExists($file->getPath())) {
            return $this->json(['error' => 'File not found.'], Response::HTTP_NOT_FOUND);
        }
        $content = $this->defaultStorage->read($file->getPath());

        return new Response($content, 200, ['Content-Type' => $file->getMime(), 'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $file->getName(), 'document.pdf'), 'Content-Length' => (string) strlen($content)]);
    }

    #[Route('/{uuid}/files/{fileId}', methods: ['DELETE'])]
    public function deleteFile(string $uuid, string $fileId): JsonResponse
    {
        $dosar = $this->findOwned($uuid, Permission::DECLARATION_SUBMIT);
        if ($dosar instanceof JsonResponse) {
            return $dosar;
        }
        $file = $this->em->getRepository(DosarFile::class)->find($fileId);
        if (!$file instanceof DosarFile || $file->getDosar() !== $dosar) {
            return $this->json(['error' => 'File not found.'], Response::HTTP_NOT_FOUND);
        }
        try {
            $this->defaultStorage->delete($file->getPath());
        } catch (\Throwable) {
        }
        $this->em->remove($file);
        $dosar->touch();
        $this->em->flush();

        return $this->json($this->detail($dosar), context: ['groups' => ['dosar:detail', 'declaration:list', 'spv_request:list', 'spv_document:list', 'dosar_file:list']]);
    }

    // ── C168 from the dosar ───────────────────────────────────────────

    /** The C168 input prefilled from the dosar, with Storno's rule issues so the user sees what is missing (address codes, tenant CNP …). */
    #[Route('/{uuid}/c168-prefill', methods: ['GET'])]
    public function c168Prefill(string $uuid, Request $request): JsonResponse
    {
        $dosar = $this->findOwned($uuid, Permission::DECLARATION_VIEW);
        if ($dosar instanceof JsonResponse) {
            return $dosar;
        }
        if ($dosar->getType() !== Dosar::TYPE_RENTAL_CONTRACT) {
            return $this->json(['error' => 'C168 se depune dintr-un dosar de contract de închiriere.', 'code' => 'WRONG_TYPE'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $actiune = (string) $request->query->get('actiune', 'inregistrare');
        if (!in_array($actiune, ['inregistrare', 'modificare', 'incetare'], true)) {
            return $this->json(['error' => 'actiune: inregistrare, modificare sau incetare.', 'code' => 'INVALID_INPUT'], Response::HTTP_BAD_REQUEST);
        }
        $input = $this->service->c168Input($dosar, $actiune);
        $result = $this->forms->get('C168')?->build($input);
        $files = $this->em->getRepository(DosarFile::class)->findBy(['dosar' => $dosar], ['createdAt' => 'DESC']);

        return $this->json(['actiune' => $actiune, 'input' => $input, 'issues' => $result?->issues ?? [], 'files' => $files, 'attachmentHint' => $actiune === 'incetare' ? 'Atașează documentul de încetare sau declarația pe propria răspundere semnată.' : ($actiune === 'modificare' ? 'Atașează actul adițional semnat.' : 'Atașează contractul scanat, semnat de ambele părți.')], context: ['groups' => ['dosar_file:list']]);
    }

    /** Create the C168 declaration in the dosar from the reviewed input, with the chosen dosar files as the zip attachment. */
    #[Route('/{uuid}/c168', methods: ['POST'])]
    public function createC168(string $uuid, Request $request): JsonResponse
    {
        $dosar = $this->findOwned($uuid, Permission::DECLARATION_SUBMIT);
        if ($dosar instanceof JsonResponse) {
            return $dosar;
        }
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }
        $body = json_decode($request->getContent(), true) ?: [];
        $actiune = (string) ($body['actiune'] ?? ($body['input']['contracte'][0]['actiune'] ?? 'inregistrare'));
        $input = is_array($body['input'] ?? null) ? $body['input'] : $this->service->c168Input($dosar, $actiune);
        $input['contracte'][0]['actiune'] = $actiune;
        $result = $this->forms->get('C168')?->build($input);
        if ($result === null) {
            return $this->json(['error' => 'C168 form unavailable.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        if ($result->hasErrors()) {
            return $this->json(['error' => 'Datele C168 au erori; corectează-le și reia.', 'code' => 'VALIDATION_FAILED', 'issues' => $result->issues, 'input' => $input], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $attachments = [];
        foreach (is_array($body['fileIds'] ?? null) ? $body['fileIds'] : [] as $fid) {
            $file = $this->em->getRepository(DosarFile::class)->find((string) $fid);
            if ($file instanceof DosarFile && $file->getDosar() === $dosar && $this->defaultStorage->fileExists($file->getPath())) {
                $attachments[] = ['name' => $file->getName(), 'contentBase64' => base64_encode($this->defaultStorage->read($file->getPath()))];
            }
        }
        foreach (is_array($body['attachments'] ?? null) ? $body['attachments'] : [] as $a) {
            if (is_array($a) && is_string($a['contentBase64'] ?? null)) {
                $attachments[] = ['name' => (string) ($a['name'] ?? 'document.pdf'), 'contentBase64' => $a['contentBase64']];
            }
        }
        if ($attachments === []) {
            return $this->json(['error' => 'C168 are nevoie de un atașament: contractul scanat, actul adițional sau documentul de încetare (încarcă-l în dosar sau trimite-l în attachments).', 'code' => 'ATTACHMENT_REQUIRED', 'issues' => $result->issues], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $declaration = $this->declarations->create($dosar->getCompany(), ['type' => DeclarationType::C168->value, 'year' => (int) ($input['an'] ?? date('Y')), 'month' => 12, 'periodType' => 'annual'], $user);
            $declaration->setData(['input' => $input, 'attachments' => $attachments]);
            $this->service->attachDeclaration($dosar, $declaration);
            $this->service->rememberC168Input($dosar, $input);
            $dosar->setNextStep(sprintf('Validează și depune C168 (%s) prin agent; o singură C168 pe perioadă poate fi în prelucrare la ANAF.', $actiune));
            $this->em->flush();
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage(), 'code' => 'VALIDATION_FAILED'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['declaration' => $declaration, 'issues' => $result->issues, 'xml' => $result->xml], Response::HTTP_CREATED, context: ['groups' => ['declaration:detail']]);
    }

    private function link(string $uuid, Request $request, bool $attach): JsonResponse
    {
        $dosar = $this->findOwned($uuid, Permission::DECLARATION_SUBMIT);
        if ($dosar instanceof JsonResponse) {
            return $dosar;
        }
        $body = json_decode($request->getContent(), true) ?: [];
        $company = $dosar->getCompany();
        $target = $attach ? $dosar : null;
        if (is_string($body['declarationId'] ?? null)) {
            $decl = $this->em->getRepository(TaxDeclaration::class)->find($body['declarationId']);
            if (!$decl instanceof TaxDeclaration || $decl->getCompany() !== $company) {
                return $this->json(['error' => 'Declaration not found.'], Response::HTTP_NOT_FOUND);
            }
            $attach ? $this->service->attachDeclaration($dosar, $decl) : $decl->setDosar(null);
        } elseif (is_string($body['requestId'] ?? null)) {
            $req = $this->em->getRepository(SpvRequest::class)->find($body['requestId']);
            if (!$req instanceof SpvRequest || $req->getCompany() !== $company) {
                return $this->json(['error' => 'Request not found.'], Response::HTTP_NOT_FOUND);
            }
            $attach ? $this->service->attachRequest($dosar, $req) : $req->setDosar($target);
        } elseif (is_string($body['documentId'] ?? null)) {
            $doc = $this->em->getRepository(SpvDocument::class)->find($body['documentId']);
            if (!$doc instanceof SpvDocument || $doc->getCompany() !== $company) {
                return $this->json(['error' => 'Document not found.'], Response::HTTP_NOT_FOUND);
            }
            $attach ? $this->service->attachDocument($dosar, $doc) : $doc->setDosar($target);
        } else {
            return $this->json(['error' => 'Trimite declarationId, requestId sau documentId.', 'code' => 'INVALID_INPUT'], Response::HTTP_BAD_REQUEST);
        }
        $dosar->touch();
        $this->em->flush();

        return $this->json($this->detail($dosar), context: ['groups' => ['dosar:detail', 'declaration:list', 'spv_request:list', 'spv_document:list', 'dosar_file:list']]);
    }

    /** @return array<string, mixed> */
    private function detail(Dosar $dosar): array
    {
        return [
            'dosar' => $dosar,
            'counts' => $this->counts($dosar),
            'declarations' => $this->em->getRepository(TaxDeclaration::class)->findBy(['dosar' => $dosar], ['createdAt' => 'DESC']),
            'requests' => $this->em->getRepository(SpvRequest::class)->findBy(['dosar' => $dosar], ['createdAt' => 'DESC']),
            'documents' => $this->em->getRepository(SpvDocument::class)->findBy(['dosar' => $dosar], ['anafCreatedAt' => 'DESC']),
            'files' => $this->em->getRepository(DosarFile::class)->findBy(['dosar' => $dosar], ['createdAt' => 'DESC']),
            'timeline' => $this->service->timeline($dosar),
        ];
    }

    /** @return array{declarations: int, requests: int, documents: int} */
    private function counts(Dosar $dosar): array
    {
        return [
            'declarations' => $this->em->getRepository(TaxDeclaration::class)->count(['dosar' => $dosar]),
            'requests' => $this->em->getRepository(SpvRequest::class)->count(['dosar' => $dosar]),
            'documents' => $this->em->getRepository(SpvDocument::class)->count(['dosar' => $dosar]),
        ];
    }

    private function findOwned(string $uuid, string $permission): Dosar|JsonResponse
    {
        if (!$this->organizationContext->hasPermission($permission)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }
        $dosar = $this->repository->find($uuid);
        if (!$dosar instanceof Dosar || !$this->organizationContext->ownsCompany($dosar->getCompany())) {
            return $this->json(['error' => 'Dosar not found.'], Response::HTTP_NOT_FOUND);
        }

        return $dosar;
    }
}
