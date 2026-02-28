<?php

namespace App\Controller\Api\V1;

use App\Entity\Supplier;
use App\Enum\InvoiceDirection;
use App\Repository\InvoiceRepository;
use App\Repository\SupplierRepository;
use App\Service\Export\SagaXmlExportService;
use App\Constants\Pagination;
use App\Security\OrganizationContext;
use App\Util\AddressNormalizer;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1')]
class SupplierController extends AbstractController
{
    public function __construct(
        private readonly SupplierRepository $supplierRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly OrganizationContext $organizationContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly SagaXmlExportService $sagaXmlExportService,
    ) {}

    #[Route('/suppliers', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::CLIENT_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $page = $request->query->getInt('page', 1);
        $limit = Pagination::clamp($request->query->getInt('limit', Pagination::DEFAULT_LIMIT));
        $search = $request->query->get('search');

        $result = $this->supplierRepository->findByCompanyGrouped($company, $page, $limit, $search);

        return $this->json([
            'data' => $result['data'],
            'total' => $result['total'],
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    #[Route('/suppliers/export/csv', methods: ['GET'])]
    public function exportCsv(Request $request): Response
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::CLIENT_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $suppliers = $this->supplierRepository->findAllByCompany($company);

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['Denumire', 'CIF', 'Cod TVA', 'Platitor TVA', 'Adresa', 'Oras', 'Judet', 'Tara', 'Email', 'Telefon', 'Banca', 'Cont bancar', 'Note']);

        foreach ($suppliers as $supplier) {
            fputcsv($handle, [
                $supplier->getName(),
                $supplier->getCif(),
                $supplier->getVatCode(),
                $supplier->isVatPayer() ? 'Da' : 'Nu',
                $supplier->getAddress(),
                $supplier->getCity(),
                $supplier->getCounty(),
                $supplier->getCountry(),
                $supplier->getEmail(),
                $supplier->getPhone(),
                $supplier->getBankName(),
                $supplier->getBankAccount(),
                $supplier->getNotes(),
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="furnizori-%s.csv"', date('Y-m-d')),
        ]);
    }

    #[Route('/suppliers/export/saga-xml', methods: ['GET'])]
    public function exportSagaXml(Request $request): Response
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::CLIENT_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $suppliers = $this->supplierRepository->findAllByCompany($company);
        $xml = $this->sagaXmlExportService->generateSuppliersXml($suppliers);

        return new Response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="FRN_%s.xml"', date('Y-m-d')),
        ]);
    }

    #[Route('/suppliers/bulk-delete', methods: ['POST'])]
    public function bulkDelete(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];

        if (!is_array($ids) || count($ids) === 0 || count($ids) > 100) {
            return $this->json(['error' => 'Provide between 1 and 100 IDs.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->organizationContext->hasPermission(Permission::CLIENT_DELETE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $deleted = 0;
        $errors = [];

        foreach ($ids as $id) {
            try {
                $supplier = $this->supplierRepository->find($id);
                if (!$supplier) {
                    $errors[] = ['id' => $id, 'error' => 'Supplier not found.'];
                    continue;
                }
                $supplier->softDelete();
                $deleted++;
            } catch (\Throwable $e) {
                $errors[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }

        $this->entityManager->flush();

        return $this->json(['deleted' => $deleted, 'errors' => $errors]);
    }

    #[Route('/suppliers', methods: ['POST'])]
    public function store(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::CLIENT_CREATE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        $name = trim($data['name'] ?? '');
        if ($name === '') {
            return $this->json(['error' => 'Name is required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $country = trim($data['country'] ?? 'RO');
        $county = trim($data['county'] ?? '');
        $city = trim($data['city'] ?? '');
        $address = trim($data['address'] ?? '');
        $registrationNumber = !empty($data['registrationNumber']) ? trim($data['registrationNumber']) : null;

        if ($county === '') {
            return $this->json(['error' => 'County is required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($city === '') {
            return $this->json(['error' => 'City is required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($address === '') {
            return $this->json(['error' => 'Address is required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$registrationNumber) {
            return $this->json(['error' => 'Registration number is required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $cif = !empty($data['cif']) ? trim($data['cif']) : null;

        // Check for existing supplier with same CIF
        if ($cif) {
            $existing = $this->supplierRepository->findByCif($company, $cif);
            if ($existing) {
                return $this->json([
                    'supplier' => $existing,
                    'existing' => true,
                ], Response::HTTP_OK, [], ['groups' => ['supplier:detail']]);
            }
        }

        $supplier = new Supplier();
        $supplier->setCompany($company);
        $supplier->setName($name);
        $supplier->setCif($cif);
        $supplier->setVatCode(!empty($data['vatCode']) ? trim($data['vatCode']) : null);
        $supplier->setIsVatPayer($data['isVatPayer'] ?? false);
        $supplier->setRegistrationNumber($registrationNumber);
        $supplier->setAddress($address);
        $normalized = AddressNormalizer::normalizeBucharest($county, $city);
        $supplier->setCity($normalized['city']);
        $supplier->setCounty($normalized['county']);
        $supplier->setCountry($country);
        $supplier->setPostalCode(!empty($data['postalCode']) ? trim($data['postalCode']) : null);
        $supplier->setEmail(!empty($data['email']) ? trim($data['email']) : null);
        $supplier->setPhone(!empty($data['phone']) ? trim($data['phone']) : null);
        $supplier->setBankName(!empty($data['bankName']) ? trim($data['bankName']) : null);
        $supplier->setBankAccount(!empty($data['bankAccount']) ? trim($data['bankAccount']) : null);
        $supplier->setNotes(!empty($data['notes']) ? trim($data['notes']) : null);
        $supplier->setSource('manual');

        $this->entityManager->persist($supplier);
        $this->entityManager->flush();

        return $this->json([
            'supplier' => $supplier,
        ], Response::HTTP_CREATED, [], ['groups' => ['supplier:detail']]);
    }

    #[Route('/suppliers/{uuid}', methods: ['GET'])]
    public function show(string $uuid, Request $request): JsonResponse
    {
        $supplier = $this->supplierRepository->find($uuid);
        if (!$supplier) {
            return $this->json(['error' => 'Supplier not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::CLIENT_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $company = $this->organizationContext->resolveCompany($request);
        $invoiceHistory = [];
        $invoiceCount = 0;

        if ($company && $supplier->getCif()) {
            $page = $request->query->getInt('page', 1);
            $limit = Pagination::clamp($request->query->getInt('limit', Pagination::DEFAULT_LIMIT));
            $result = $this->invoiceRepository->findByCifPaginated($company, $supplier->getCif(), $page, $limit, InvoiceDirection::INCOMING);
            $invoiceHistory = $result['data'] ?? [];
            $invoiceCount = $result['total'] ?? 0;
        }

        return $this->json([
            'supplier' => $supplier,
            'invoiceHistory' => array_values($invoiceHistory),
            'invoiceCount' => $invoiceCount,
            'invoiceTotal' => $invoiceCount,
        ], context: ['groups' => ['supplier:detail', 'invoice:list']]);
    }

    #[Route('/suppliers/{uuid}', methods: ['PATCH'])]
    public function update(string $uuid, Request $request): JsonResponse
    {
        $supplier = $this->supplierRepository->find($uuid);
        if (!$supplier) {
            return $this->json(['error' => 'Supplier not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::CLIENT_EDIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('name', $data)) {
            $name = trim($data['name']);
            if ($name === '') {
                return $this->json(['error' => 'Name cannot be empty.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $supplier->setName($name);
        }
        if (array_key_exists('cif', $data)) $supplier->setCif(!empty($data['cif']) ? trim($data['cif']) : null);
        if (array_key_exists('vatCode', $data)) $supplier->setVatCode(!empty($data['vatCode']) ? trim($data['vatCode']) : null);
        if (array_key_exists('isVatPayer', $data)) $supplier->setIsVatPayer($data['isVatPayer'] ?? false);
        if (array_key_exists('registrationNumber', $data)) {
            $regNum = !empty($data['registrationNumber']) ? trim($data['registrationNumber']) : null;
            if (!$regNum) {
                return $this->json(['error' => 'Registration number cannot be empty.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $supplier->setRegistrationNumber($regNum);
        }
        if (array_key_exists('address', $data)) {
            $addr = trim($data['address'] ?? '');
            if ($addr === '') {
                return $this->json(['error' => 'Address cannot be empty.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $supplier->setAddress($addr);
        }
        if (array_key_exists('city', $data) || array_key_exists('county', $data)) {
            $cityVal = array_key_exists('city', $data) ? trim($data['city'] ?? '') : $supplier->getCity();
            $countyVal = array_key_exists('county', $data) ? trim($data['county'] ?? '') : $supplier->getCounty();
            if (array_key_exists('city', $data) && ($cityVal === '' || $cityVal === null)) {
                return $this->json(['error' => 'City cannot be empty.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            if (array_key_exists('county', $data) && ($countyVal === '' || $countyVal === null)) {
                return $this->json(['error' => 'County cannot be empty.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $normalized = AddressNormalizer::normalizeBucharest($countyVal ?? '', $cityVal ?? '');
            $supplier->setCity($normalized['city']);
            $supplier->setCounty($normalized['county']);
        }
        if (array_key_exists('country', $data)) {
            $countryVal = trim($data['country'] ?? '');
            if ($countryVal === '') {
                return $this->json(['error' => 'Country cannot be empty.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $supplier->setCountry($countryVal);
        }
        if (array_key_exists('postalCode', $data)) $supplier->setPostalCode(!empty($data['postalCode']) ? trim($data['postalCode']) : null);
        if (array_key_exists('email', $data)) $supplier->setEmail(!empty($data['email']) ? trim($data['email']) : null);
        if (array_key_exists('phone', $data)) $supplier->setPhone(!empty($data['phone']) ? trim($data['phone']) : null);
        if (array_key_exists('bankName', $data)) $supplier->setBankName(!empty($data['bankName']) ? trim($data['bankName']) : null);
        if (array_key_exists('bankAccount', $data)) $supplier->setBankAccount(!empty($data['bankAccount']) ? trim($data['bankAccount']) : null);
        if (array_key_exists('notes', $data)) $supplier->setNotes(!empty($data['notes']) ? trim($data['notes']) : null);

        $this->entityManager->flush();

        return $this->json([
            'supplier' => $supplier,
        ], context: ['groups' => ['supplier:detail']]);
    }

    #[Route('/suppliers/{uuid}', methods: ['DELETE'])]
    public function delete(string $uuid): JsonResponse
    {
        $supplier = $this->supplierRepository->find($uuid);
        if (!$supplier) {
            return $this->json(['error' => 'Supplier not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::CLIENT_DELETE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $supplier->softDelete();
        $this->entityManager->flush();

        return $this->json(['message' => 'Supplier deleted.']);
    }
}
