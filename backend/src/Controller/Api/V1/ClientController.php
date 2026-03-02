<?php

namespace App\Controller\Api\V1;

use App\Entity\Client;
use App\Enum\InvoiceDirection;
use App\Manager\ClientManager;
use App\Repository\ClientRepository;
use App\Repository\DeliveryNoteRepository;
use App\Repository\InvoiceRepository;
use App\Repository\ReceiptRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Service\Export\SagaXmlExportService;
use App\Constants\Pagination;
use App\Services\AnafService;
use App\Service\Vies\ViesService;
use App\Util\AddressNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/clients')]
class ClientController extends AbstractController
{
    public function __construct(
        private readonly ClientManager $clientManager,
        private readonly ClientRepository $clientRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly DeliveryNoteRepository $deliveryNoteRepository,
        private readonly ReceiptRepository $receiptRepository,
        private readonly OrganizationContext $organizationContext,
        private readonly AnafService $anafService,
        private readonly EntityManagerInterface $entityManager,
        private readonly SagaXmlExportService $sagaXmlExportService,
        private readonly ViesService $viesService,
    ) {}

    #[Route('', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::CLIENT_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $page = $request->query->getInt('page', 1);
        $limit = Pagination::clamp($request->query->getInt('limit', Pagination::DEFAULT_LIMIT));
        $search = $request->query->get('search');

        $result = $this->clientManager->listGrouped($company, $page, $limit, $search);

        $response = $this->json([
            'data' => $result['data'],
            'total' => $result['total'],
            'page' => $page,
            'limit' => $limit,
        ]);
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

        if (!$this->organizationContext->hasPermission(Permission::CLIENT_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $clients = $this->clientRepository->findAllByCompany($company);

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['Tip', 'Denumire', 'CUI', 'CNP', 'Cod TVA', 'Platitor TVA', 'Nr. Reg. Com.', 'Adresa', 'Oras', 'Judet', 'Tara', 'Cod Postal', 'Email', 'Telefon', 'Banca', 'Cont bancar', 'Persoana contact', 'Termen plata (zile)']);

        foreach ($clients as $client) {
            fputcsv($handle, [
                $client->getType(),
                $client->getName(),
                $client->getCui(),
                $client->getCnp(),
                $client->getVatCode(),
                $client->isVatPayer() ? 'Da' : 'Nu',
                $client->getRegistrationNumber(),
                $client->getAddress(),
                $client->getCity(),
                $client->getCounty(),
                $client->getCountry(),
                $client->getPostalCode(),
                $client->getEmail(),
                $client->getPhone(),
                $client->getBankName(),
                $client->getBankAccount(),
                $client->getContactPerson(),
                $client->getDefaultPaymentTermDays(),
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="clienti-%s.csv"', date('Y-m-d')),
        ]);
    }

    #[Route('/export/saga-xml', methods: ['GET'])]
    public function exportSagaXml(Request $request): Response
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::CLIENT_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $clients = $this->clientRepository->findAllByCompany($company);
        $xml = $this->sagaXmlExportService->generateClientsXml($clients);

        return new Response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="CLI_%s.xml"', date('Y-m-d')),
        ]);
    }

    #[Route('/vies-lookup', methods: ['GET'])]
    public function viesLookup(Request $request): JsonResponse
    {
        $vatCode = trim($request->query->get('vatCode', ''));
        if ($vatCode === '') {
            return $this->json(['error' => 'vatCode is required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $parsed = $this->viesService->parseVatCode($vatCode);
        if (!$parsed) {
            return $this->json(['error' => 'Invalid VAT code format. Expected format: DE123456789'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $this->viesService->validate($parsed['countryCode'], $parsed['vatNumber']);
        if ($result === null) {
            return $this->json(['error' => 'VIES service unavailable.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->json(['data' => $result]);
    }

    /**
     * Create a client manually.
     */
    #[Route('', methods: ['POST'])]
    public function store(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::CLIENT_CREATE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        $errors = [];

        $name = trim($data['name'] ?? '');
        if ($name === '') {
            $errors[] = ['key' => 'name', 'message' => 'Name is required.'];
        }

        $country = trim($data['country'] ?? 'RO');
        $county = trim($data['county'] ?? '');
        $city = trim($data['city'] ?? '');
        $address = trim($data['address'] ?? '');

        if ($country === 'RO' && $county === '') {
            $errors[] = ['key' => 'county', 'message' => 'County is required.'];
        }
        if ($city === '') {
            $errors[] = ['key' => 'city', 'message' => 'City is required.'];
        }
        if ($address === '') {
            $errors[] = ['key' => 'address', 'message' => 'Address is required.'];
        }

        $type = $data['type'] ?? 'company';
        $registrationNumber = !empty($data['registrationNumber']) ? trim($data['registrationNumber']) : null;
        if ($type === 'company' && $country === 'RO' && !$registrationNumber) {
            $errors[] = ['key' => 'registrationNumber', 'message' => 'Registration number is required for companies.'];
        }

        if ($errors) {
            return $this->json(['errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $cui = !empty($data['cui']) ? trim($data['cui']) : null;

        // Check for existing client with same CUI
        if ($cui) {
            $existing = $this->clientRepository->findByCui($company, $cui);
            if ($existing) {
                return $this->json([
                    'client' => $existing,
                    'existing' => true,
                ], Response::HTTP_OK, [], ['groups' => ['client:list']]);
            }
        }

        $client = new Client();
        $client->setCompany($company);
        $client->setName($name);
        $client->setCui($cui);
        $client->setType($type);
        $client->setCnp(!empty($data['cnp']) ? trim($data['cnp']) : null);
        $client->setVatCode(!empty($data['vatCode']) ? trim($data['vatCode']) : null);
        $client->setIsVatPayer($data['isVatPayer'] ?? false);
        $client->setRegistrationNumber($registrationNumber);
        $client->setAddress($address);
        if ($country === 'RO' && $county !== '' && $city !== '') {
            // Normalize Bucharest sectors to UBL-compliant format
            $normalized = AddressNormalizer::normalizeBucharest($county, $city);
            $client->setCity($normalized['city']);
            $client->setCounty($normalized['county']);
        } else {
            $client->setCity($city ?: null);
            $client->setCounty($county ?: null);
        }
        $client->setCountry($country);
        $client->setPostalCode(!empty($data['postalCode']) ? trim($data['postalCode']) : null);
        $client->setEmail(!empty($data['email']) ? trim($data['email']) : null);
        $client->setPhone(!empty($data['phone']) ? trim($data['phone']) : null);
        $client->setBankName(!empty($data['bankName']) ? trim($data['bankName']) : null);
        $client->setBankAccount(!empty($data['bankAccount']) ? trim($data['bankAccount']) : null);
        $client->setDefaultPaymentTermDays($data['defaultPaymentTermDays'] ?? null);
        $client->setContactPerson(!empty($data['contactPerson']) ? trim($data['contactPerson']) : null);
        $client->setNotes(!empty($data['notes']) ? trim($data['notes']) : null);
        $client->setSource('manual');

        $this->autoValidateVies($client);

        $this->entityManager->persist($client);
        $this->entityManager->flush();

        return $this->json([
            'client' => $client,
        ], Response::HTTP_CREATED, [], ['groups' => ['client:list']]);
    }

    /**
     * Lookup a CUI in ANAF without creating a client — returns company details for form pre-fill.
     */
    #[Route('/anaf-lookup', methods: ['GET'])]
    public function anafLookup(Request $request): JsonResponse
    {
        $cui = trim($request->query->get('cui', ''));
        if ($cui === '') {
            return $this->json(['error' => 'CUI is required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $cuiClean = preg_replace('/^RO/i', '', $cui);

        try {
            $anafInfo = $this->anafService->findCompany($cuiClean);
        } catch (\Throwable) {
            return $this->json(['error' => 'ANAF lookup failed.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (!$anafInfo) {
            return $this->json(['error' => 'CUI not found in ANAF.'], Response::HTTP_NOT_FOUND);
        }

        $normalized = AddressNormalizer::normalizeBucharest(
            $anafInfo->getState(),
            $anafInfo->getCity(),
        );

        return $this->json([
            'data' => [
                'cui' => (string) $anafInfo->getCif(),
                'name' => $anafInfo->getName(),
                'address' => $anafInfo->getAddress(),
                'city' => $normalized['city'],
                'county' => $normalized['county'],
                'postalCode' => $anafInfo->getPostalCode(),
                'phone' => $anafInfo->getPhone(),
                'registrationNumber' => $anafInfo->getRegistrationNumber(),
                'isVatPayer' => $anafInfo->isVatPayer(),
                'vatCode' => $anafInfo->getVatCode(),
            ],
        ]);
    }

    /**
     * Create a client from ONRC registry — validates CUI with ANAF and auto-fills details.
     */
    #[Route('/from-registry', methods: ['POST'])]
    public function storeFromRegistry(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::CLIENT_CREATE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $cui = trim($data['cui'] ?? '');

        if ($cui === '') {
            return $this->json(['error' => 'CUI is required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Strip RO prefix for lookup
        $cuiClean = preg_replace('/^RO/i', '', $cui);

        // Check if client already exists with this CUI
        $existing = $this->clientRepository->findByCui($company, $cuiClean);
        if ($existing) {
            return $this->json([
                'client' => $existing,
                'existing' => true,
            ], Response::HTTP_OK, [], ['groups' => ['client:list']]);
        }

        // Validate with ANAF
        try {
            $anafInfo = $this->anafService->findCompany($cuiClean);
        } catch (\Throwable) {
            $anafInfo = null;
        }

        $client = new Client();
        $client->setCompany($company);
        $client->setCui($cuiClean);
        $client->setSource('manual');

        if ($anafInfo) {
            $client->setName($anafInfo->getName());
            $client->setAddress($anafInfo->getAddress());
            $anafAddr = AddressNormalizer::normalizeBucharest($anafInfo->getState(), $anafInfo->getCity());
            $client->setCity($anafAddr['city']);
            $client->setCounty($anafAddr['county']);
            $client->setPostalCode($anafInfo->getPostalCode());
            $client->setPhone($anafInfo->getPhone());
            $client->setRegistrationNumber($anafInfo->getRegistrationNumber());
            $client->setIsVatPayer($anafInfo->isVatPayer());
            $client->setVatCode($anafInfo->getVatCode());
        } else {
            // Fallback to registry name if ANAF lookup fails
            $client->setName(trim($data['name'] ?? 'CUI ' . $cuiClean));
        }

        $this->entityManager->persist($client);
        $this->entityManager->flush();

        return $this->json([
            'client' => $client,
            'anafValidated' => $anafInfo !== null,
        ], Response::HTTP_CREATED, [], ['groups' => ['client:list']]);
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
                $client = $this->clientRepository->find(Uuid::fromString($id));
                if (!$client) {
                    $errors[] = ['id' => $id, 'error' => 'Client not found.'];
                    continue;
                }
                $this->denyAccessUnlessGranted('CLIENT_DELETE', $client);
                $client->softDelete();
                $deleted++;
            } catch (\Throwable $e) {
                $errors[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }

        $this->entityManager->flush();

        return $this->json(['deleted' => $deleted, 'errors' => $errors]);
    }

    #[Route('/{uuid}', methods: ['PATCH'])]
    public function update(string $uuid, Request $request): JsonResponse
    {
        $client = $this->clientRepository->find(Uuid::fromString($uuid));
        if (!$client) {
            return $this->json(['error' => 'Client not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('CLIENT_EDIT', $client);

        $data = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('name', $data)) {
            $name = trim($data['name']);
            if ($name === '') {
                return $this->json(['error' => 'Name cannot be empty.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $client->setName($name);
        }
        if (array_key_exists('type', $data)) $client->setType($data['type']);
        if (array_key_exists('cui', $data)) $client->setCui(!empty($data['cui']) ? trim($data['cui']) : null);
        if (array_key_exists('cnp', $data)) $client->setCnp(!empty($data['cnp']) ? trim($data['cnp']) : null);
        if (array_key_exists('vatCode', $data)) $client->setVatCode(!empty($data['vatCode']) ? trim($data['vatCode']) : null);
        if (array_key_exists('isVatPayer', $data)) $client->setIsVatPayer($data['isVatPayer'] ?? false);
        if (array_key_exists('registrationNumber', $data)) {
            $regNum = !empty($data['registrationNumber']) ? trim($data['registrationNumber']) : null;
            $effectiveType = $data['type'] ?? $client->getType();
            $effectiveCountry = $data['country'] ?? $client->getCountry() ?? 'RO';
            if ($effectiveType === 'company' && $effectiveCountry === 'RO' && !$regNum) {
                return $this->json(['error' => 'Registration number is required for companies.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $client->setRegistrationNumber($regNum);
        }
        if (array_key_exists('address', $data)) {
            $addr = trim($data['address'] ?? '');
            if ($addr === '') {
                return $this->json(['error' => 'Address cannot be empty.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $client->setAddress($addr);
        }
        if (array_key_exists('city', $data) || array_key_exists('county', $data)) {
            $effectiveCountry = $data['country'] ?? $client->getCountry() ?? 'RO';
            $county = array_key_exists('county', $data) ? trim($data['county'] ?? '') : $client->getCounty();
            $city = array_key_exists('city', $data) ? trim($data['city'] ?? '') : $client->getCity();
            if ($effectiveCountry === 'RO' && array_key_exists('county', $data) && ($county === '' || $county === null)) {
                return $this->json(['error' => 'County cannot be empty.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            if (array_key_exists('city', $data) && ($city === '' || $city === null)) {
                return $this->json(['error' => 'City cannot be empty.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            // Normalize Bucharest sectors to UBL-compliant format
            if ($county !== null && $city !== null) {
                $normalized = AddressNormalizer::normalizeBucharest($county, $city);
                $county = $normalized['county'];
                $city = $normalized['city'];
            }
            $client->setCity($city);
            $client->setCounty($county);
        }
        if (array_key_exists('country', $data)) {
            $countryVal = trim($data['country'] ?? '');
            if ($countryVal === '') {
                return $this->json(['error' => 'Country cannot be empty.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $client->setCountry($countryVal);
        }
        if (array_key_exists('postalCode', $data)) $client->setPostalCode(!empty($data['postalCode']) ? trim($data['postalCode']) : null);
        if (array_key_exists('email', $data)) $client->setEmail(!empty($data['email']) ? trim($data['email']) : null);
        if (array_key_exists('phone', $data)) $client->setPhone(!empty($data['phone']) ? trim($data['phone']) : null);
        if (array_key_exists('bankName', $data)) $client->setBankName(!empty($data['bankName']) ? trim($data['bankName']) : null);
        if (array_key_exists('bankAccount', $data)) $client->setBankAccount(!empty($data['bankAccount']) ? trim($data['bankAccount']) : null);
        if (array_key_exists('defaultPaymentTermDays', $data)) $client->setDefaultPaymentTermDays($data['defaultPaymentTermDays'] ?? null);
        if (array_key_exists('contactPerson', $data)) $client->setContactPerson(!empty($data['contactPerson']) ? trim($data['contactPerson']) : null);
        if (array_key_exists('notes', $data)) $client->setNotes(!empty($data['notes']) ? trim($data['notes']) : null);

        // Re-validate VIES if vatCode or country changed
        if (array_key_exists('vatCode', $data) || array_key_exists('country', $data)) {
            $this->autoValidateVies($client);
        }

        $this->entityManager->flush();

        return $this->json([
            'client' => $client,
        ], Response::HTTP_OK, [], ['groups' => ['client:detail']]);
    }

    #[Route('/{uuid}', methods: ['DELETE'])]
    public function delete(string $uuid): JsonResponse
    {
        $client = $this->clientRepository->find(Uuid::fromString($uuid));
        if (!$client) {
            return $this->json(['error' => 'Client not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('CLIENT_DELETE', $client);

        $client->softDelete();
        $this->entityManager->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{uuid}', methods: ['GET'])]
    public function show(string $uuid, Request $request): JsonResponse
    {
        $client = $this->clientRepository->find(Uuid::fromString($uuid));
        if (!$client) {
            return $this->json(['error' => 'Client not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('CLIENT_VIEW', $client);

        $company = $this->resolveCompany($request);
        $invoiceHistory = [];
        $invoiceTotal = 0;
        $invoiceCount = 0;
        $deliveryNoteHistory = [];
        $deliveryNoteCount = 0;
        $receiptHistory = [];
        $receiptCount = 0;

        if ($company) {
            $clientId = (string) $client->getId();
            $cif = $client->getCui() ?? $client->getCnp();

            if ($cif) {
                $page = $request->query->getInt('page', 1);
                $limit = Pagination::clamp($request->query->getInt('limit', Pagination::DEFAULT_LIMIT));
                $result = $this->invoiceRepository->findByCifPaginated($company, $cif, $page, $limit, InvoiceDirection::OUTGOING);
                $invoiceHistory = $result['data'] ?? [];
                $invoiceTotal = $result['total'] ?? 0;
                $invoiceCount = $invoiceTotal;
            } else {
                // Fallback to client FK if no identification number
                $result = $this->invoiceRepository->findByCompanyPaginated(
                    $company,
                    ['clientId' => $clientId],
                    1,
                    50,
                );
                $invoiceHistory = $result['data'] ?? [];
                $invoiceTotal = $result['total'] ?? 0;
                $invoiceCount = $invoiceTotal;
            }

            $deliveryNoteHistory = $this->deliveryNoteRepository->findRecentByClient($company, $clientId, 5);
            $deliveryNoteCount = $this->deliveryNoteRepository->countByClient($company, $clientId);

            $receiptHistory = $this->receiptRepository->findRecentByClient($company, $clientId, 5);
            $receiptCount = $this->receiptRepository->countByClient($company, $clientId);
        }

        return $this->json([
            'client' => $client,
            'invoiceHistory' => array_values($invoiceHistory),
            'invoiceTotal' => $invoiceTotal,
            'invoiceCount' => $invoiceCount,
            'deliveryNoteHistory' => $deliveryNoteHistory,
            'deliveryNoteCount' => $deliveryNoteCount,
            'receiptHistory' => $receiptHistory,
            'receiptCount' => $receiptCount,
        ], context: ['groups' => ['client:detail', 'invoice:list', 'delivery_note:list', 'receipt:list']]);
    }

    private function resolveCompany(Request $request): ?\App\Entity\Company
    {
        return $this->organizationContext->resolveCompany($request);
    }

    private function autoValidateVies(Client $client): void
    {
        // Only validate foreign clients with a VAT code
        if ($client->getCountry() === 'RO' || empty($client->getVatCode())) {
            $client->setViesValid(null);
            $client->setViesValidatedAt(null);
            $client->setViesName(null);
            return;
        }

        $parsed = $this->viesService->parseVatCode($client->getVatCode());
        if (!$parsed) {
            $client->setViesValid(null);
            $client->setViesValidatedAt(null);
            $client->setViesName(null);
            return;
        }

        $result = $this->viesService->validate($parsed['countryCode'], $parsed['vatNumber']);
        if ($result === null) {
            // API failure — don't change existing VIES status
            return;
        }

        $client->setViesValid($result['valid']);
        $client->setViesValidatedAt(new \DateTimeImmutable());
        $client->setViesName($result['name']);
    }
}
