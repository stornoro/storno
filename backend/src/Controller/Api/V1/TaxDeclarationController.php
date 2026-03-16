<?php

namespace App\Controller\Api\V1;

use App\Manager\TaxDeclarationManager;
use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Constants\Pagination;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1')]
class TaxDeclarationController extends AbstractController
{
    public function __construct(
        private readonly TaxDeclarationManager $manager,
        private readonly OrganizationContext $organizationContext,
        private readonly FilesystemOperator $defaultStorage,
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

    private function resolveCompany(Request $request): ?\App\Entity\Company
    {
        return $this->organizationContext->resolveCompany($request);
    }
}
