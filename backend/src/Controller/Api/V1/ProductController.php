<?php

namespace App\Controller\Api\V1;

use App\Entity\Product;
use App\Manager\ProductManager;
use App\Repository\CompanyRepository;
use App\Repository\ProductRepository;
use App\Service\Export\SagaXmlExportService;
use App\Constants\Pagination;
use App\Security\OrganizationContext;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/products')]
class ProductController extends AbstractController
{
    public function __construct(
        private readonly ProductManager $productManager,
        private readonly OrganizationContext $organizationContext,
        private readonly CompanyRepository $companyRepository,
        private readonly ProductRepository $productRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly SagaXmlExportService $sagaXmlExportService,
    ) {}

    #[Route('', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::PRODUCT_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $page = $request->query->getInt('page', 1);
        $limit = Pagination::clamp($request->query->getInt('limit', Pagination::DEFAULT_LIMIT));
        $search = $request->query->get('search');

        $paginator = $this->productManager->list($company, $page, $limit, $search);

        $response = $this->json([
            'data' => array_values(iterator_to_array($paginator)),
            'total' => count($paginator),
            'page' => $page,
            'limit' => $limit,
        ], context: ['groups' => ['product:list']]);
        $response->setMaxAge(30);
        $response->setPrivate();
        $response->setVary(['X-Company', 'Authorization']);

        return $response;
    }

    #[Route('/export/csv', methods: ['GET'])]
    public function exportCsv(Request $request): Response
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::PRODUCT_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $products = $this->productRepository->findAllByCompany($company);

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['Denumire', 'Cod', 'Descriere', 'UM', 'Pret', 'Moneda', 'Cota TVA', 'Categorie TVA', 'Serviciu', 'Activ', 'Utilizare', 'Cod NC', 'Cod CPV']);

        foreach ($products as $product) {
            fputcsv($handle, [
                $product->getName(),
                $product->getCode(),
                $product->getDescription(),
                $product->getUnitOfMeasure(),
                $product->getDefaultPrice(),
                $product->getCurrency(),
                $product->getVatRate(),
                $product->getVatCategoryCode(),
                $product->isService() ? 'Da' : 'Nu',
                $product->isActive() ? 'Da' : 'Nu',
                $product->getUsage(),
                $product->getNcCode(),
                $product->getCpvCode(),
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="produse-%s.csv"', date('Y-m-d')),
        ]);
    }

    #[Route('/export/saga-xml', methods: ['GET'])]
    public function exportSagaXml(Request $request): Response
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::PRODUCT_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $products = $this->productRepository->findAllByCompany($company);
        $xml = $this->sagaXmlExportService->generateProductsXml($products);

        return new Response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="ART_%s.xml"', date('Y-m-d')),
        ]);
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::PRODUCT_CREATE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);

        $errors = [];
        $name = trim($data['name'] ?? '');
        if (!$name) {
            $errors[] = ['key' => 'name', 'message' => 'Denumirea articolului trebuie completata.'];
        }
        if (empty($data['unitOfMeasure'])) {
            $errors[] = ['key' => 'unitOfMeasure', 'message' => 'Alege o unitate de masura valida.'];
        }
        if (!isset($data['vatRate']) || $data['vatRate'] === '') {
            $errors[] = ['key' => 'vatRate', 'message' => 'Cota TVA trebuie selectata.'];
        }
        if (!isset($data['defaultPrice']) || $data['defaultPrice'] === '') {
            $errors[] = ['key' => 'defaultPrice', 'message' => 'Completeaza pretul.'];
        }
        if ($errors) {
            return $this->json(['errors' => $errors], Response::HTTP_BAD_REQUEST);
        }

        $product = new Product();
        $product->setCompany($company);
        $product->setName($name);
        $product->setSource('manual');

        if (isset($data['code'])) {
            $product->setCode($data['code']);
        }
        if (isset($data['description'])) {
            $product->setDescription($data['description']);
        }
        if (isset($data['unitOfMeasure'])) {
            $product->setUnitOfMeasure($data['unitOfMeasure']);
        }
        if (isset($data['defaultPrice'])) {
            $product->setDefaultPrice((string) $data['defaultPrice']);
        }
        if (isset($data['currency'])) {
            $product->setCurrency($data['currency']);
        }
        if (isset($data['vatRate'])) {
            $product->setVatRate((string) $data['vatRate']);
        }
        if (isset($data['vatCategoryCode'])) {
            $product->setVatCategoryCode($data['vatCategoryCode']);
        }
        if (isset($data['isService'])) {
            $product->setIsService((bool) $data['isService']);
        }
        if (isset($data['usage'])) {
            $product->setUsage($data['usage']);
        }
        if (array_key_exists('ncCode', $data)) {
            $product->setNcCode($data['ncCode'] ?: null);
        }
        if (array_key_exists('cpvCode', $data)) {
            $product->setCpvCode($data['cpvCode'] ?: null);
        }

        $this->entityManager->persist($product);
        $this->entityManager->flush();

        return $this->json($product, Response::HTTP_CREATED, context: ['groups' => ['product:detail']]);
    }

    #[Route('/bulk-delete', methods: ['POST'])]
    public function bulkDelete(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];

        if (!is_array($ids) || count($ids) === 0 || count($ids) > 100) {
            return $this->json(['error' => 'Provide between 1 and 100 IDs.'], Response::HTTP_BAD_REQUEST);
        }

        $deleted = 0;
        $errors = [];

        foreach ($ids as $id) {
            try {
                $product = $this->productRepository->find(Uuid::fromString($id));
                if (!$product) {
                    $errors[] = ['id' => $id, 'error' => 'Product not found.'];
                    continue;
                }
                $this->denyAccessUnlessGranted('PRODUCT_DELETE', $product);
                $product->softDelete();
                $deleted++;
            } catch (\Throwable $e) {
                $errors[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }

        $this->entityManager->flush();

        return $this->json(['deleted' => $deleted, 'errors' => $errors]);
    }

    #[Route('/{uuid}', methods: ['GET'])]
    public function show(string $uuid): JsonResponse
    {
        $product = $this->productRepository->find(Uuid::fromString($uuid));
        if (!$product) {
            return $this->json(['error' => 'Product not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('PRODUCT_VIEW', $product);

        return $this->json($product, context: ['groups' => ['product:detail']]);
    }

    #[Route('/{uuid}', methods: ['PATCH'])]
    public function update(string $uuid, Request $request): JsonResponse
    {
        $product = $this->productRepository->find(Uuid::fromString($uuid));
        if (!$product) {
            return $this->json(['error' => 'Product not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('PRODUCT_EDIT', $product);

        $data = json_decode($request->getContent(), true);

        if (isset($data['name'])) {
            $product->setName($data['name']);
        }
        if (array_key_exists('code', $data)) {
            $product->setCode($data['code']);
        }
        if (array_key_exists('description', $data)) {
            $product->setDescription($data['description']);
        }
        if (isset($data['unitOfMeasure'])) {
            $product->setUnitOfMeasure($data['unitOfMeasure']);
        }
        if (isset($data['defaultPrice'])) {
            $product->setDefaultPrice((string) $data['defaultPrice']);
        }
        if (isset($data['currency'])) {
            $product->setCurrency($data['currency']);
        }
        if (isset($data['vatRate'])) {
            $product->setVatRate((string) $data['vatRate']);
        }
        if (isset($data['vatCategoryCode'])) {
            $product->setVatCategoryCode($data['vatCategoryCode']);
        }
        if (isset($data['isService'])) {
            $product->setIsService((bool) $data['isService']);
        }
        if (isset($data['isActive'])) {
            $product->setIsActive((bool) $data['isActive']);
        }
        if (isset($data['usage'])) {
            $product->setUsage($data['usage']);
        }
        if (array_key_exists('ncCode', $data)) {
            $product->setNcCode($data['ncCode'] ?: null);
        }
        if (array_key_exists('cpvCode', $data)) {
            $product->setCpvCode($data['cpvCode'] ?: null);
        }

        $this->entityManager->flush();

        return $this->json($product, context: ['groups' => ['product:detail']]);
    }

    #[Route('/{uuid}/usage', methods: ['GET'])]
    public function usage(string $uuid): JsonResponse
    {
        $product = $this->productRepository->find(Uuid::fromString($uuid));
        if (!$product) {
            return $this->json(['error' => 'Product not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('PRODUCT_VIEW', $product);

        return $this->json($this->getProductUsage($product));
    }

    #[Route('/{uuid}', methods: ['DELETE'])]
    public function delete(string $uuid): JsonResponse
    {
        $product = $this->productRepository->find(Uuid::fromString($uuid));
        if (!$product) {
            return $this->json(['error' => 'Product not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('PRODUCT_DELETE', $product);

        $product->softDelete();
        $this->entityManager->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function getProductUsage(Product $product): array
    {
        $conn = $this->entityManager->getConnection();
        $productId = $product->getId()->toBinary();

        $invoiceCount = (int) $conn->fetchOne(
            'SELECT COUNT(DISTINCT il.invoice_id) FROM invoice_line il WHERE il.product_id = ?',
            [$productId]
        );
        $proformaCount = (int) $conn->fetchOne(
            'SELECT COUNT(DISTINCT pl.proforma_invoice_id) FROM proforma_invoice_line pl WHERE pl.product_id = ?',
            [$productId]
        );
        $recurringCount = (int) $conn->fetchOne(
            'SELECT COUNT(DISTINCT rl.recurring_invoice_id) FROM recurring_invoice_line rl WHERE rl.product_id = ?',
            [$productId]
        );

        return [
            'invoices' => $invoiceCount,
            'proformaInvoices' => $proformaCount,
            'recurringInvoices' => $recurringCount,
            'total' => $invoiceCount + $proformaCount + $recurringCount,
        ];
    }

    private function resolveCompany(Request $request): ?\App\Entity\Company
    {
        return $this->organizationContext->resolveCompany($request);
    }
}
