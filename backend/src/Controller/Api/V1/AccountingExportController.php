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
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use ZipStream\ZipStream;

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

        // Build account map: stored settings, overridden by per-export options.accounts.
        $settings = $company->getExportSettingsWithDefaults();
        $sagaSettings = $settings['saga'] ?? [];
        $overrides = is_array($options['accounts'] ?? null) ? $options['accounts'] : [];
        $accountCash = trim((string) ($overrides['cash'] ?? $sagaSettings['accountCash'] ?? ''));
        $accountBank = trim((string) ($overrides['bank'] ?? $sagaSettings['accountBank'] ?? ''));
        $accountCard = trim((string) ($overrides['card'] ?? $sagaSettings['accountCard'] ?? ''));
        $accountClients = trim((string) ($overrides['clients'] ?? $sagaSettings['accountClients'] ?? '4111'));
        $accountSuppliers = trim((string) ($overrides['suppliers'] ?? $sagaSettings['accountSuppliers'] ?? '4011'));

        $ronAccountMap = [];
        if ($accountCash !== '') {
            $ronAccountMap['cash'] = $accountCash;
        }
        if ($accountBank !== '') {
            $ronAccountMap['bank_transfer'] = $accountBank;
        }
        if ($accountCard !== '') {
            $ronAccountMap['card'] = $accountCard;
        }

        $storedCurrencyAccounts = is_array($sagaSettings['currencyAccounts'] ?? null) ? $sagaSettings['currencyAccounts'] : [];
        $overrideCurrencyAccounts = is_array($options['currencyAccounts'] ?? null) ? $options['currencyAccounts'] : [];
        $currencyAccounts = array_replace_recursive($storedCurrencyAccounts, $overrideCurrencyAccounts);

        // Time-series — filtered by date range
        $invoiceFilters = ['direction' => 'outgoing', 'excludeCancelled' => true];
        if ($dateFrom) {
            $invoiceFilters['dateFrom'] = $dateFrom;
        }
        if ($dateTo) {
            $invoiceFilters['dateTo'] = $dateTo;
        }
        // No limit: an accounting export must contain every invoice in range.
        $invoices = $this->invoiceRepository->findByCompanyFiltered($company, $invoiceFilters, null);

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

        // Master data. With a date range, scope partners to those referenced by the
        // in-range documents so the import matches the period; otherwise full list.
        // Products have no reference on invoice lines, so always the full nomenclature.
        $products = $this->productRepository->findAllByCompany($company);
        if ($dateFrom || $dateTo) {
            $clients = $this->collectClients($invoices, $receipts);
            $suppliers = $this->collectSuppliers($payments);
        } else {
            $clients = $this->clientRepository->findAllByCompany($company);
            $suppliers = $this->supplierRepository->findAllByCompany($company);
        }

        // Generate SAGA XML files
        $dateSuffix = date('d_m_Y');
        $cif = (string) $company->getCif();
        $files = [
            "cli_{$dateSuffix}.xml" => $this->sagaXmlExportService->generateClientsXml($clients),
            "frn_{$dateSuffix}.xml" => $this->sagaXmlExportService->generateSuppliersXml($suppliers),
            "art_{$dateSuffix}.xml" => $this->sagaXmlExportService->generateProductsXml($products),
            'f_' . $this->sagaDateRange($invoices, fn (\App\Entity\Invoice $i) => $i->getIssueDate()) . '.xml' => $this->sagaXmlExportService->generateInvoicesXml($invoices, $company, $includeDiscount),
        ];

        foreach ($this->splitByCurrency($receipts, $ronAccountMap, $currencyAccounts) as $suffix => [$group, $map]) {
            $range = $this->sagaDateRange($group, fn (\App\Entity\Payment $p) => $p->getPaymentDate());
            $files["i_{$range}{$suffix}.xml"] = $this->sagaXmlExportService->generateReceiptsXml($group, $map);
        }
        foreach ($this->splitByCurrency($payments, $ronAccountMap, $currencyAccounts) as $suffix => [$group, $map]) {
            $range = $this->sagaDateRange($group, fn (\App\Entity\Payment $p) => $p->getPaymentDate());
            $files["p_{$range}{$suffix}.xml"] = $this->sagaXmlExportService->generatePaymentsXml($group, $map);
        }

        // Conditionally include account assignment files
        if ($exportAccounts) {
            $files["conturi_cli_{$dateSuffix}.xml"] = $this->sagaXmlExportService->generateClientAccountsXml(
                $clients,
                $accountClients !== '' ? $accountClients : '4111',
            );
            $files["conturi_frn_{$dateSuffix}.xml"] = $this->sagaXmlExportService->generateSupplierAccountsXml(
                $suppliers,
                $accountSuppliers !== '' ? $accountSuppliers : '4011',
            );
        }

        // Conditionally include BNR exchange rates
        if ($exportBnr) {
            $files["curs_bnr_{$dateSuffix}.xml"] = $this->sagaXmlExportService->generateBnrRatesXml();
        }

        // Stream the ZIP straight to the client (ZipStream → php://output):
        // no temp file and no full-archive copy held in memory.
        $downloadName = sprintf('saga-export_%s_%s.zip', $cif, $dateSuffix);

        $response = new StreamedResponse(function () use ($files): void {
            $zip = new ZipStream(
                sendHttpHeaders: false,
                defaultEnableZeroHeader: true,
                flushOutput: true,
            );
            foreach ($files as $filename => $content) {
                $zip->addFile(fileName: $filename, data: $content);
            }
            $zip->finish();
        });

        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $downloadName,
        ));
        // Disable proxy/FastCGI buffering so bytes reach the client as written.
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    /**
     * Distinct clients referenced by the in-range sales invoices and receipts.
     *
     * @param \App\Entity\Invoice[] $invoices
     * @param \App\Entity\Payment[] $receipts
     * @return \App\Entity\Client[]
     */
    private function collectClients(array $invoices, array $receipts): array
    {
        $clients = [];
        foreach ($invoices as $invoice) {
            $client = $invoice->getClient();
            if ($client) {
                $clients[(string) $client->getId()] = $client;
            }
        }
        foreach ($receipts as $receipt) {
            $client = $receipt->getInvoice()?->getClient();
            if ($client) {
                $clients[(string) $client->getId()] = $client;
            }
        }

        return array_values($clients);
    }

    /**
     * Distinct suppliers referenced by the in-range outgoing payments.
     *
     * @param \App\Entity\Payment[] $payments
     * @return \App\Entity\Supplier[]
     */
    private function collectSuppliers(array $payments): array
    {
        $suppliers = [];
        foreach ($payments as $payment) {
            $supplier = $payment->getInvoice()?->getSupplier();
            if ($supplier) {
                $suppliers[(string) $supplier->getId()] = $supplier;
            }
        }

        return array_values($suppliers);
    }

    /**
     * @param \App\Entity\Payment[] $payments
     * @return array<string, array{0: \App\Entity\Payment[], 1: array<string,string>}>
     */
    /**
     * Build the {start}_{end} date span (dd-mm-yyyy, dash-separated) used in
     * SAGA import filenames, e.g. "01-01-2026_31-01-2026". Start/end are the
     * min/max of the extracted date across the docs; an empty set falls back
     * to today for both.
     *
     * @param object[] $docs
     */
    private function sagaDateRange(array $docs, callable $dateExtractor): string
    {
        $start = null;
        $end = null;
        foreach ($docs as $doc) {
            $d = $dateExtractor($doc);
            if (!$d instanceof \DateTimeInterface) {
                continue;
            }
            if ($start === null || $d < $start) {
                $start = $d;
            }
            if ($end === null || $d > $end) {
                $end = $d;
            }
        }

        $fallback = new \DateTimeImmutable('today');
        $start ??= $fallback;
        $end ??= $fallback;

        return $start->format('d-m-Y') . '_' . $end->format('d-m-Y');
    }

    private function splitByCurrency(array $payments, array $ronAccountMap, array $currencyAccounts): array
    {
        $byCurrency = [];
        foreach ($payments as $p) {
            $byCurrency[strtoupper($p->getCurrency() ?: 'RON')][] = $p;
        }

        if (empty($byCurrency)) {
            return ['' => [[], $ronAccountMap]];
        }

        if (count($byCurrency) === 1 && array_key_exists('RON', $byCurrency)) {
            return ['' => [$byCurrency['RON'], $ronAccountMap]];
        }

        $out = [];
        foreach ($byCurrency as $currency => $group) {
            $suffix = '_' . $currency;
            $out[$suffix] = [$group, $this->buildAccountMap($currency, $ronAccountMap, $currencyAccounts)];
        }
        ksort($out);
        return $out;
    }

    private function buildAccountMap(string $currency, array $ronAccountMap, array $currencyAccounts): array
    {
        if ($currency === 'RON') {
            return $ronAccountMap;
        }
        $override = $currencyAccounts[$currency] ?? [];
        $map = $ronAccountMap;
        foreach (['cash' => 'cash', 'bank' => 'bank_transfer', 'card' => 'card'] as $optKey => $methodKey) {
            $v = trim((string) ($override[$optKey] ?? ''));
            if ($v !== '') {
                $map[$methodKey] = $v;
            }
        }
        return $map;
    }
}
