<?php

namespace App\Controller\Api\V1;

use App\Entity\Dosar;
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
use App\Service\Dosar\DosarService;
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
    public function stats(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json($this->service->stats($company));
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

        return $this->json($this->detail($dosar), Response::HTTP_CREATED, context: ['groups' => ['dosar:detail', 'declaration:list', 'spv_request:list', 'spv_document:list']]);
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

        return $this->json($this->detail($dosar), context: ['groups' => ['dosar:detail', 'declaration:list', 'spv_request:list', 'spv_document:list']]);
    }

    #[Route('/{uuid}', methods: ['GET'])]
    public function show(string $uuid): JsonResponse
    {
        $dosar = $this->findOwned($uuid, Permission::DECLARATION_VIEW);
        if ($dosar instanceof JsonResponse) {
            return $dosar;
        }

        return $this->json($this->detail($dosar), context: ['groups' => ['dosar:detail', 'declaration:list', 'spv_request:list', 'spv_document:list']]);
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

        return $this->json($this->detail($dosar), context: ['groups' => ['dosar:detail', 'declaration:list', 'spv_request:list', 'spv_document:list']]);
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
        if ($type === 'declaratie_incetare_contract' || $type === 'conventie_incetare_inchiriere') {
            $dosar->setNextStep('Semnează documentul de încetare, apoi depune C168 încetare cu el atașat (termen 30 de zile).')->touch();
            $this->em->flush();
        }

        return $this->json(['type' => $type, 'title' => $doc['title'], 'fileName' => $fileName, 'pdfBase64' => base64_encode($doc['pdf'])]);
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

        return $this->json($this->detail($dosar), context: ['groups' => ['dosar:detail', 'declaration:list', 'spv_request:list', 'spv_document:list']]);
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
