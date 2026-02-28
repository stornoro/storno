<?php

namespace App\Controller\Api\V1;

use App\Enum\InvoiceDirection;
use App\Repository\ClientRepository;
use App\Repository\InvoiceRepository;
use App\Repository\PaymentRepository;
use App\Repository\ProductRepository;
use App\Repository\SupplierRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Service\Export\SagaXmlExportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/accounting-export')]
class AccountingExportController extends AbstractController
{
    public function __construct(
        private readonly OrganizationContext $organizationContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClientRepository $clientRepository,
        private readonly SupplierRepository $supplierRepository,
        private readonly ProductRepository $productRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly SagaXmlExportService $sagaXmlExportService,
    ) {}

    #[Route('/settings', methods: ['GET'])]
    public function getSettings(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::SETTINGS_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json($company->getExportSettingsWithDefaults());
    }

    #[Route('/settings', methods: ['PUT'])]
    public function updateSettings(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::SETTINGS_MANAGE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON body.'], Response::HTTP_BAD_REQUEST);
        }

        // Merge incoming settings onto existing
        $current = $company->getExportSettings() ?? [];
        $merged = array_replace_recursive($current, $data);
        $company->setExportSettings($merged);

        $this->entityManager->flush();

        return $this->json($company->getExportSettingsWithDefaults());
    }

    #[Route('/zip', methods: ['POST'])]
    public function exportZip(Request $request): Response
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::EXPORT_DATA)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $target = $data['target'] ?? 'saga';
        $dateFrom = $data['dateFrom'] ?? null;
        $dateTo = $data['dateTo'] ?? null;
        $options = $data['options'] ?? [];

        if (!in_array($target, ['saga', 'winmentor', 'ciel'], true)) {
            return $this->json(['error' => 'Target invalid. Valori acceptate: saga, winmentor, ciel.'], Response::HTTP_BAD_REQUEST);
        }

        if ($target !== 'saga') {
            return $this->json(['error' => 'Exportul pentru ' . ucfirst($target) . ' va fi disponibil in curand.'], Response::HTTP_BAD_REQUEST);
        }

        $includeDiscount = !empty($options['includeDiscount']);
        $exportAccounts = $options['exportAccounts'] ?? true;
        $exportBnr = !empty($options['exportBnr']);

        // Build account map from company settings
        $settings = $company->getExportSettingsWithDefaults();
        $sagaSettings = $settings['saga'] ?? [];
        $accountMap = [];
        if (!empty($sagaSettings['accountCash'])) {
            $accountMap['cash'] = $sagaSettings['accountCash'];
        }
        if (!empty($sagaSettings['accountBank'])) {
            $accountMap['bank_transfer'] = $sagaSettings['accountBank'];
        }
        if (!empty($sagaSettings['accountCard'])) {
            $accountMap['card'] = $sagaSettings['accountCard'];
        }

        // Master data — always full list
        $clients = $this->clientRepository->findAllByCompany($company);
        $suppliers = $this->supplierRepository->findAllByCompany($company);
        $products = $this->productRepository->findAllByCompany($company);

        // Time-series — filtered by date range
        $invoiceFilters = ['direction' => 'outgoing'];
        if ($dateFrom) {
            $invoiceFilters['dateFrom'] = $dateFrom;
        }
        if ($dateTo) {
            $invoiceFilters['dateTo'] = $dateTo;
        }
        $invoices = $this->invoiceRepository->findByCompanyFiltered($company, $invoiceFilters);

        $receipts = $this->paymentRepository->findByCompanyAndDirectionFiltered(
            $company,
            InvoiceDirection::OUTGOING,
            $dateFrom,
            $dateTo,
        );
        $payments = $this->paymentRepository->findByCompanyAndDirectionFiltered(
            $company,
            InvoiceDirection::INCOMING,
            $dateFrom,
            $dateTo,
        );

        // Generate SAGA XML files
        $dateSuffix = date('d_m_Y');
        $cif = (string) $company->getCif();
        $files = [
            "cli_{$dateSuffix}.xml" => $this->sagaXmlExportService->generateClientsXml($clients),
            "frn_{$dateSuffix}.xml" => $this->sagaXmlExportService->generateSuppliersXml($suppliers),
            "art_{$dateSuffix}.xml" => $this->sagaXmlExportService->generateProductsXml($products),
            "F_{$cif}_multiple_{$dateSuffix}.xml" => $this->sagaXmlExportService->generateInvoicesXml($invoices, $company, $includeDiscount),
            "inc_{$dateSuffix}.xml" => $this->sagaXmlExportService->generateReceiptsXml($receipts, $accountMap),
            "plt_{$dateSuffix}.xml" => $this->sagaXmlExportService->generatePaymentsXml($payments, $accountMap),
        ];

        // Conditionally include account assignment files
        if ($exportAccounts) {
            $files["conturi_cli_{$dateSuffix}.xml"] = $this->sagaXmlExportService->generateClientAccountsXml(
                $clients,
                $sagaSettings['accountClients'] ?? '4111',
            );
            $files["conturi_frn_{$dateSuffix}.xml"] = $this->sagaXmlExportService->generateSupplierAccountsXml(
                $suppliers,
                $sagaSettings['accountSuppliers'] ?? '4011',
            );
        }

        // Conditionally include BNR exchange rates
        if ($exportBnr) {
            $files["curs_bnr_{$dateSuffix}.xml"] = $this->sagaXmlExportService->generateBnrRatesXml();
        }

        // Bundle into ZIP
        $tmpFile = tempnam(sys_get_temp_dir(), 'saga_export_');
        $zip = new \ZipArchive();
        $zip->open($tmpFile, \ZipArchive::OVERWRITE);

        foreach ($files as $filename => $content) {
            $zip->addFromString($filename, $content);
        }

        $zip->close();

        $zipContent = file_get_contents($tmpFile);
        @unlink($tmpFile);

        $response = new Response($zipContent, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => sprintf('attachment; filename="saga-export_%s_%s.zip"', $cif, $dateSuffix),
            'Content-Length' => strlen($zipContent),
        ]);

        return $response;
    }
}
