<?php

namespace App\Controller\Api\V1;

use App\Entity\ImportJob;
use App\Message\ProcessImportMessage;
use App\Repository\ImportJobRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Service\Import\ImportOrchestrator;
use App\Service\LicenseManager;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/import')]
class ImportController extends AbstractController
{
    private const ALLOWED_EXTENSIONS = ['csv', 'xlsx', 'xml'];

    private const SOURCE_LABELS = [
        'smartbill'       => 'SmartBill',
        'saga'            => 'SAGA',
        'oblio'           => 'Oblio',
        'fgo'             => 'FGO',
        'facturis_online' => 'FacturisOnline',
        'easybill'        => 'EasyBill',
        'ciel'            => 'Ciel',
        'factureaza'      => 'Factureaza',
        'facturare_pro'   => 'FacturarePro',
        'icefact'         => 'IceFact',
        'bolt'            => 'Bolt',
        'facturis'        => 'Facturis',
        'emag'            => 'eMag',
        'generic'         => 'Altul (generic)',
    ];

    private const IMPORT_TYPE_LABELS = [
        'clients'              => 'Clienți',
        'products'             => 'Produse',
        'invoices_issued'      => 'Facturi emise',
        'invoices_received'    => 'Facturi primite',
        'recurring_invoices'   => 'Facturi recurente',
    ];

    public function __construct(
        private readonly ImportOrchestrator $orchestrator,
        private readonly EntityManagerInterface $entityManager,
        private readonly FilesystemOperator $defaultStorage,
        private readonly MessageBusInterface $messageBus,
        private readonly ImportJobRepository $importJobRepository,
        private readonly OrganizationContext $organizationContext,
        private readonly LicenseManager $licenseManager,
    ) {}

    /**
     * GET /api/v1/import/sources
     *
     * Returns the list of available import sources and import types for the UI.
     */
    #[Route('/sources', methods: ['GET'])]
    public function sources(): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        if (!$this->organizationContext->hasPermission(Permission::IMPORT_MANAGE)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $availableSources = $this->orchestrator->getAvailableSources();

        // Enrich with labels
        foreach ($availableSources as &$source) {
            $source['label'] = self::SOURCE_LABELS[$source['key']] ?? $source['key'];
        }
        unset($source);

        $importTypes = [];
        foreach (self::IMPORT_TYPE_LABELS as $type => $label) {
            $importTypes[] = ['value' => $type, 'label' => $label];
        }

        return $this->json([
            'sources'     => $availableSources,
            'importTypes' => $importTypes,
        ]);
    }

    /**
     * POST /api/v1/import/upload
     *
     * Accepts a multipart upload (file, importType, source), stores the file,
     * creates an ImportJob, runs preview synchronously, and returns the job.
     */
    #[Route('/upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        if (!$this->organizationContext->hasPermission(Permission::IMPORT_MANAGE)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        $org = $company->getOrganization();
        if (!$this->licenseManager->canImportExport($org)) {
            return $this->json([
                'error' => 'Import is not available on your plan.',
                'code' => 'PLAN_LIMIT',
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        $uploadedFile = $request->files->get('file');
        if (!$uploadedFile) {
            return $this->json(['error' => 'No file uploaded.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $originalFilename = $uploadedFile->getClientOriginalName();
        $extension = strtolower($uploadedFile->getClientOriginalExtension());

        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return $this->json(
                ['error' => sprintf('Invalid file type. Allowed: %s.', implode(', ', self::ALLOWED_EXTENSIONS))],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $importType = $request->request->get('importType', '');
        if (!array_key_exists($importType, self::IMPORT_TYPE_LABELS)) {
            return $this->json(
                ['error' => 'Invalid importType. Allowed: ' . implode(', ', array_keys(self::IMPORT_TYPE_LABELS)) . '.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $source = $request->request->get('source', '');
        if (!array_key_exists($source, self::SOURCE_LABELS)) {
            return $this->json(
                ['error' => 'Invalid source. Allowed: ' . implode(', ', array_keys(self::SOURCE_LABELS)) . '.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        // Determine file format from extension
        $fileFormat = match ($extension) {
            'xml'  => 'saga_xml',
            default => $extension,
        };

        // Store file via Flysystem
        $storagePath = sprintf('imports/%s/%s.%s', (string) $company->getId(), Uuid::v7()->toRfc4122(), $extension);

        try {
            $stream = fopen($uploadedFile->getPathname(), 'r');
            $this->defaultStorage->writeStream($storagePath, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        } catch (\Throwable $e) {
            return $this->json(['error' => 'File storage failed: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Create import job
        $job = new ImportJob();
        $job->setCompany($company);
        $job->setImportType($importType);
        $job->setSource($source);
        $job->setFileFormat($fileFormat);
        $job->setOriginalFilename($originalFilename);
        $job->setStoragePath($storagePath);
        $job->setStatus('pending');

        $this->entityManager->persist($job);
        $this->entityManager->flush();

        // Run preview synchronously
        try {
            $this->orchestrator->preparePreview($job);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $job->setStatus('failed');
            $job->setErrors([['row' => 0, 'field' => 'file', 'message' => $e->getMessage()]]);
            $this->entityManager->flush();
        }

        $targetFields = $this->orchestrator->getTargetFields($job->getImportType());

        return $this->json(
            ['job' => $job, 'targetFields' => $targetFields],
            Response::HTTP_CREATED,
            [],
            ['groups' => ['import_job:detail']],
        );
    }

    /**
     * GET /api/v1/import/template
     *
     * Returns a CSV template for the given import type with column headers and example rows.
     */
    #[Route('/template', methods: ['GET'])]
    public function template(Request $request): StreamedResponse|JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        if (!$this->organizationContext->hasPermission(Permission::IMPORT_MANAGE)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $importType = $request->query->get('importType', '');
        if (!array_key_exists($importType, self::IMPORT_TYPE_LABELS)) {
            return $this->json(
                ['error' => 'Invalid importType. Allowed: ' . implode(', ', array_keys(self::IMPORT_TYPE_LABELS)) . '.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $targetFields = $this->orchestrator->getTargetFields($importType);
        if (empty($targetFields)) {
            return $this->json(['error' => 'No target fields found for this import type.'], Response::HTTP_NOT_FOUND);
        }

        $headers = array_values($targetFields);
        $fieldKeys = array_keys($targetFields);

        // Build example rows based on import type
        $exampleRows = $this->getExampleRows($importType, $fieldKeys);

        $filename = sprintf('model_import_%s.csv', $importType);

        $response = new StreamedResponse(function () use ($headers, $exampleRows) {
            $output = fopen('php://output', 'w');

            // UTF-8 BOM for Excel compatibility
            fwrite($output, "\xEF\xBB\xBF");

            fputcsv($output, $headers);

            foreach ($exampleRows as $row) {
                fputcsv($output, $row);
            }

            fclose($output);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $response;
    }

    /**
     * GET /api/v1/import/{id}/preview
     *
     * Returns the job with preview data, detected columns, suggested mapping,
     * and the target fields available for the job's import type.
     */
    #[Route('/{id}/preview', methods: ['GET'])]
    public function preview(string $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        if (!$this->organizationContext->hasPermission(Permission::IMPORT_MANAGE)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $job = $this->resolveJob($id, $request);
        if ($job instanceof JsonResponse) {
            return $job;
        }

        $targetFields = $this->orchestrator->getTargetFields($job->getImportType());

        return $this->json(
            [
                'job'          => $job,
                'targetFields' => $targetFields,
            ],
            Response::HTTP_OK,
            [],
            ['groups' => ['import_job:detail']],
        );
    }

    /**
     * PATCH /api/v1/import/{id}/mapping
     *
     * Saves the user-confirmed column mapping onto the job and advances status to 'mapping'.
     */
    #[Route('/{id}/mapping', methods: ['PATCH'])]
    public function mapping(string $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        if (!$this->organizationContext->hasPermission(Permission::IMPORT_MANAGE)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $job = $this->resolveJob($id, $request);
        if ($job instanceof JsonResponse) {
            return $job;
        }

        $data = json_decode($request->getContent(), true) ?? [];

        if (!isset($data['columnMapping']) || !is_array($data['columnMapping'])) {
            return $this->json(['error' => 'columnMapping is required and must be an object.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $job->setColumnMapping($data['columnMapping']);
        $job->setStatus('mapping');

        $this->entityManager->flush();

        return $this->json(
            ['job' => $job],
            Response::HTTP_OK,
            [],
            ['groups' => ['import_job:detail']],
        );
    }

    /**
     * POST /api/v1/import/{id}/execute
     *
     * Validates the job has a column mapping, sets status to 'processing',
     * and dispatches the async ProcessImportMessage.
     */
    #[Route('/{id}/execute', methods: ['POST'])]
    public function execute(string $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        if (!$this->organizationContext->hasPermission(Permission::IMPORT_MANAGE)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $job = $this->resolveJob($id, $request);
        if ($job instanceof JsonResponse) {
            return $job;
        }

        if (empty($job->getColumnMapping())) {
            return $this->json(
                ['error' => 'Column mapping must be configured before executing the import.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ($job->getStatus() === 'processing') {
            return $this->json(['error' => 'Import is already being processed.'], Response::HTTP_CONFLICT);
        }

        if ($job->getStatus() === 'completed') {
            return $this->json(['error' => 'Import has already been completed.'], Response::HTTP_CONFLICT);
        }

        // Accept optional importOptions from request body
        $data = json_decode($request->getContent(), true) ?? [];
        if (isset($data['importOptions']) && is_array($data['importOptions'])) {
            $job->setImportOptions($data['importOptions']);
        }

        $job->setStatus('processing');
        $this->entityManager->flush();

        $this->messageBus->dispatch(new ProcessImportMessage((string) $job->getId()));

        return $this->json(
            ['job' => $job],
            Response::HTTP_OK,
            [],
            ['groups' => ['import_job:detail']],
        );
    }

    /**
     * GET /api/v1/import/history
     *
     * Returns the list of past import jobs for the current company.
     */
    #[Route('/history', methods: ['GET'])]
    public function history(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        if (!$this->organizationContext->hasPermission(Permission::IMPORT_MANAGE)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        $limit = min((int) $request->query->get('limit', 50), 200);
        $jobs = $this->importJobRepository->findByCompany($company, $limit);

        return $this->json(
            ['data' => $jobs],
            Response::HTTP_OK,
            [],
            ['groups' => ['import_job:list']],
        );
    }

    /**
     * GET /api/v1/import/{id}
     *
     * Returns the full job status and details.
     */
    #[Route('/{id}', methods: ['GET'])]
    public function show(string $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        if (!$this->organizationContext->hasPermission(Permission::IMPORT_MANAGE)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $job = $this->resolveJob($id, $request);
        if ($job instanceof JsonResponse) {
            return $job;
        }

        return $this->json(
            ['job' => $job],
            Response::HTTP_OK,
            [],
            ['groups' => ['import_job:detail']],
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function resolveCompany(Request $request): ?\App\Entity\Company
    {
        return $this->organizationContext->resolveCompany($request);
    }

    /**
     * Returns 1-2 example rows for the template CSV based on import type.
     *
     * @param string[] $fieldKeys Target field keys in order
     * @return array<array<string>>
     */
    private function getExampleRows(string $importType, array $fieldKeys): array
    {
        $examples = match ($importType) {
            'clients' => [
                ['name' => 'SC Exemplu SRL', 'cui' => 'RO12345678', 'registrationNumber' => 'J40/1234/2020', 'address' => 'Str. Exemplu nr. 1', 'city' => 'Bucuresti', 'county' => 'Bucuresti', 'country' => 'RO', 'postalCode' => '010101', 'email' => 'contact@exemplu.ro', 'phone' => '0721000000', 'bankName' => 'Banca Transilvania', 'bankAccount' => 'RO49AAAA1B31007593840000', 'defaultPaymentTermDays' => '30', 'contactPerson' => 'Ion Popescu', 'clientCode' => 'C001'],
                ['name' => 'SC Test SRL', 'cui' => '87654321', 'registrationNumber' => 'J12/5678/2019', 'address' => 'Bd. Unirii nr. 10', 'city' => 'Cluj-Napoca', 'county' => 'Cluj', 'country' => 'RO', 'postalCode' => '400001', 'email' => 'info@test.ro', 'phone' => '0722000000', 'bankName' => 'BRD', 'bankAccount' => 'RO12BRDE445SV00000001234', 'defaultPaymentTermDays' => '15', 'contactPerson' => 'Maria Ionescu', 'clientCode' => 'C002'],
            ],
            'products' => [
                ['name' => 'Serviciu consultanta', 'code' => 'SRV001', 'description' => 'Consultanta IT', 'unitOfMeasure' => 'ora', 'defaultPrice' => '150.00', 'currency' => 'RON', 'vatRate' => '19', 'isService' => 'Da'],
                ['name' => 'Laptop Dell', 'code' => 'PRD001', 'description' => 'Laptop business', 'unitOfMeasure' => 'buc', 'defaultPrice' => '4500.00', 'currency' => 'RON', 'vatRate' => '19', 'isService' => 'Nu'],
            ],
            'invoices_issued' => [
                ['number' => 'FV001', 'issueDate' => '2026-01-15', 'dueDate' => '2026-02-15', 'senderName' => '', 'senderCif' => '', 'receiverName' => 'SC Client SRL', 'receiverCif' => 'RO12345678', 'subtotal' => '1000.00', 'vatTotal' => '190.00', 'total' => '1190.00', 'currency' => 'RON', 'paymentMethod' => 'transfer bancar', 'notes' => '', 'lineDescription' => 'Serviciu consultanta', 'lineQuantity' => '10', 'lineUnitOfMeasure' => 'ora', 'lineUnitPrice' => '100.00', 'lineVatRate' => '19', 'lineVatAmount' => '190.00', 'lineTotal' => '1000.00', 'lineProductCode' => 'SRV001'],
            ],
            'invoices_received' => [
                ['number' => 'FZ001', 'issueDate' => '2026-01-10', 'dueDate' => '2026-02-10', 'senderName' => 'SC Furnizor SRL', 'senderCif' => 'RO87654321', 'receiverName' => '', 'receiverCif' => '', 'subtotal' => '500.00', 'vatTotal' => '95.00', 'total' => '595.00', 'currency' => 'RON', 'paymentMethod' => 'transfer bancar', 'notes' => '', 'lineDescription' => 'Materiale birou', 'lineQuantity' => '1', 'lineUnitOfMeasure' => 'set', 'lineUnitPrice' => '500.00', 'lineVatRate' => '19', 'lineVatAmount' => '95.00', 'lineTotal' => '500.00', 'lineProductCode' => 'MAT001'],
            ],
            'recurring_invoices' => [
                ['reference' => 'REC-001', 'clientName' => 'SC Exemplu SRL', 'clientCif' => 'RO12345678', 'currency' => 'RON', 'seriesName' => 'FV', 'description' => 'Chirie birou', 'frequency' => 'Lunar', 'isActive' => 'Da', 'nextIssuanceDate' => '2026-03-01', 'frequencyDay' => '1', 'dueDateDays' => '30', 'dueDateFixedDay' => '', 'penaltyEnabled' => 'Nu', 'penaltyPercentPerDay' => '', 'penaltyGraceDays' => '', 'autoEmailEnabled' => 'Da', 'autoEmailTime' => '09:00', 'autoEmailDayOffset' => '0', 'lineDescription' => 'Chirie birou luna curenta', 'lineProductCode' => 'SRV001', 'lineUnitOfMeasure' => 'buc', 'lineVatRate' => '19', 'lineQuantity' => '1', 'lineUnitPrice' => '2000.00', 'lineTotal' => '2000.00', 'linePriceRule' => 'fix', 'lineReferenceCurrency' => ''],
            ],
            default => [],
        };

        // Map example data to field order
        $rows = [];
        foreach ($examples as $example) {
            $row = [];
            foreach ($fieldKeys as $key) {
                $row[] = $example[$key] ?? '';
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Resolves an ImportJob by UUID string, verifies it belongs to the current
     * company, and returns it — or a JsonResponse error if anything is wrong.
     *
     * @return ImportJob|JsonResponse
     */
    private function resolveJob(string $id, Request $request): ImportJob|JsonResponse
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\Throwable) {
            return $this->json(['error' => 'Invalid import job ID.'], Response::HTTP_BAD_REQUEST);
        }

        $job = $this->importJobRepository->find($uuid);
        if (!$job) {
            return $this->json(['error' => 'Import job not found.'], Response::HTTP_NOT_FOUND);
        }

        $company = $this->resolveCompany($request);
        if (!$company || (string) $job->getCompany()?->getId() !== (string) $company->getId()) {
            return $this->json(['error' => 'Import job not found.'], Response::HTTP_NOT_FOUND);
        }

        return $job;
    }
}
