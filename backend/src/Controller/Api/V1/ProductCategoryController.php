<?php

namespace App\Controller\Api\V1;

use App\Entity\ProductCategory;
use App\Repository\ProductCategoryRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/product-categories')]
class ProductCategoryController extends AbstractController
{
    public function __construct(
        private readonly ProductCategoryRepository $repository,
        private readonly OrganizationContext $organizationContext,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->organizationContext->hasPermission(Permission::PRODUCT_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $categories = $this->repository->findByCompanyOrdered($company);

        return $this->json(
            ['data' => $categories],
            Response::HTTP_OK,
            context: ['groups' => ['product_category:list']],
        );
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->organizationContext->hasPermission(Permission::PRODUCT_EDIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return $this->json(['error' => 'name is required.'], Response::HTTP_BAD_REQUEST);
        }

        $category = new ProductCategory();
        $category->setCompany($company);
        $category->setName($name);
        $category->setColor($this->normalizeColor($data['color'] ?? null));
        $category->setSortOrder((int) ($data['sortOrder'] ?? 0));

        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return $this->json(
            $category,
            Response::HTTP_CREATED,
            context: ['groups' => ['product_category:detail']],
        );
    }

    #[Route('/{uuid}', methods: ['PATCH'], requirements: ['uuid' => '[0-9a-f-]{36}'])]
    public function update(string $uuid, Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->organizationContext->hasPermission(Permission::PRODUCT_EDIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $category = $this->repository->find(Uuid::fromString($uuid));
        if (!$category || $category->getCompany()?->getId()->toRfc4122() !== $company->getId()->toRfc4122()) {
            return $this->json(['error' => 'Category not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return $this->json(['error' => 'name cannot be empty.'], Response::HTTP_BAD_REQUEST);
            }
            $category->setName($name);
        }
        if (array_key_exists('color', $data)) {
            $category->setColor($this->normalizeColor($data['color']));
        }
        if (array_key_exists('sortOrder', $data)) {
            $category->setSortOrder((int) $data['sortOrder']);
        }

        $category->touchUpdatedAt();
        $this->entityManager->flush();

        return $this->json(
            $category,
            Response::HTTP_OK,
            context: ['groups' => ['product_category:detail']],
        );
    }

    #[Route('/{uuid}', methods: ['DELETE'], requirements: ['uuid' => '[0-9a-f-]{36}'])]
    public function delete(string $uuid, Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->organizationContext->hasPermission(Permission::PRODUCT_EDIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $category = $this->repository->find(Uuid::fromString($uuid));
        if (!$category || $category->getCompany()?->getId()->toRfc4122() !== $company->getId()->toRfc4122()) {
            return $this->json(['error' => 'Category not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($category);
        $this->entityManager->flush();

        return $this->json(['message' => 'Category deleted.']);
    }

    private function normalizeColor(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value)) return null;
        $trimmed = trim($value);
        if (preg_match('/^#?([0-9a-fA-F]{6})$/', $trimmed, $m) === 1) {
            return '#' . strtolower($m[1]);
        }
        return null;
    }
}
