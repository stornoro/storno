<?php

namespace App\Controller\Api\V1;

use App\Entity\TrialBalance;
use App\Message\ProcessTrialBalanceMessage;
use App\Repository\TrialBalanceRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Service\Balance\BalanceAnalysisService;
use App\Service\Balance\TrialBalancePdfParser;
use App\Service\LicenseManager;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/balances')]
class BalanceController extends AbstractController
{
    public function __construct(
        private readonly OrganizationContext $organizationContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly FilesystemOperator $defaultStorage,
        private readonly MessageBusInterface $messageBus,
        private readonly TrialBalanceRepository $trialBalanceRepository,
        private readonly BalanceAnalysisService $analysisService,
        private readonly TrialBalancePdfParser $pdfParser,
        private readonly LicenseManager $licenseManager,
    ) {}

    #[Route('/upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::REPORT_VIEW)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $org = $company->getOrganization();
        if (!$this->licenseManager->canViewReports($org)) {
            return $this->json([
                'error' => 'Reports are not available on your plan.',
                'code' => 'PLAN_LIMIT',
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        // Accept multiple files via files[] or single file via file
        $files = $request->files->get('files', []);
        if (!is_array($files)) {
            $files = [$files];
        }
        $singleFile = $request->files->get('file');
        if ($singleFile) {
            $files[] = $singleFile;
        }

        if (empty($files)) {
            return $this->json(['error' => 'No files uploaded.'], Response::HTTP_BAD_REQUEST);
        }

        $results = [];
        $companyId = (string) $company->getId();

        foreach ($files as $file) {
            $filename = $file->getClientOriginalName();

            if ($file->getMimeType() !== 'application/pdf') {
                $results[] = ['filename' => $filename, 'success' => false, 'error' => 'Only PDF files are accepted.', 'code' => 'INVALID_TYPE'];
                continue;
            }

            if ($file->getSize() > 10 * 1024 * 1024) {
                $results[] = ['filename' => $filename, 'success' => false, 'error' => 'File size must not exceed 10MB.', 'code' => 'FILE_TOO_LARGE'];
                continue;
            }

            $pdfContent = file_get_contents($file->getPathname());
            $contentHash = hash('sha256', $pdfContent);

            // Check for duplicate file
            $duplicate = $this->trialBalanceRepository->findByCompanyAndContentHash($company, $contentHash);
            if ($duplicate) {
                $results[] = ['filename' => $filename, 'success' => false, 'error' => 'This file has already been uploaded.', 'code' => 'DUPLICATE_FILE'];
                continue;
            }

            // Parse PDF to detect year/month from content
            try {
                $parsed = $this->pdfParser->parse($pdfContent);
            } catch (\Throwable $e) {
                $results[] = ['filename' => $filename, 'success' => false, 'error' => 'Failed to parse PDF: ' . $e->getMessage(), 'code' => 'PARSE_ERROR'];
                continue;
            }

            // Validate company CUI if detected from PDF
            if ($parsed->companyCui !== null) {
                $companyCif = (string) $company->getCif();
                if ($parsed->companyCui !== $companyCif) {
                    $results[] = [
                        'filename' => $filename,
                        'success' => false,
                        'error' => sprintf('CUI mismatch: PDF contains CUI %s but the selected company has CUI %s.', $parsed->companyCui, $companyCif),
                        'code' => 'CUI_MISMATCH',
                    ];
                    continue;
                }
            }

            $year = $parsed->year;
            $month = $parsed->month;

            if (!$year || $year < 2000 || $year > 2100) {
                $results[] = ['filename' => $filename, 'success' => false, 'error' => 'Could not detect year from PDF.', 'code' => 'NO_YEAR'];
                continue;
            }

            if (!$month || $month < 1 || $month > 12) {
                $results[] = ['filename' => $filename, 'success' => false, 'error' => 'Could not detect month from PDF.', 'code' => 'NO_MONTH'];
                continue;
            }

            // Check for existing balance — replace if found
            $existing = $this->trialBalanceRepository->findByCompanyYearMonth($company, $year, $month);
            if ($existing) {
                // Bulk-delete rows first to avoid FK constraint violation
                $this->entityManager->createQuery('DELETE FROM App\Entity\TrialBalanceRow r WHERE r.trialBalance = :tb')
                    ->setParameter('tb', $existing)
                    ->execute();
                if ($existing->getStoragePath()) {
                    try {
                        $this->defaultStorage->delete($existing->getStoragePath());
                    } catch (\Throwable) {
                    }
                }
                $this->entityManager->remove($existing);
                $this->entityManager->flush();
            }

            // Store file
            $storagePath = "balances/{$companyId}/" . Uuid::v7() . '.pdf';
            $this->defaultStorage->write($storagePath, $pdfContent);

            // Create entity
            $trialBalance = new TrialBalance();
            $trialBalance->setCompany($company);
            $trialBalance->setYear($year);
            $trialBalance->setMonth($month);
            $trialBalance->setOriginalFilename($filename);
            $trialBalance->setStoragePath($storagePath);
            $trialBalance->setContentHash($contentHash);
            if ($parsed->sourceSoftware) {
                $trialBalance->setSourceSoftware($parsed->sourceSoftware);
            }

            $this->entityManager->persist($trialBalance);
            $this->entityManager->flush();

            // Dispatch async processing (row parsing happens in background)
            $this->messageBus->dispatch(new ProcessTrialBalanceMessage((string) $trialBalance->getId()));

            $results[] = [
                'filename' => $filename,
                'success' => true,
                'id' => (string) $trialBalance->getId(),
                'year' => $year,
                'month' => $month,
                'status' => $trialBalance->getStatus(),
                'totalAccounts' => $trialBalance->getTotalAccounts(),
            ];
        }

        return $this->json(['results' => $results], Response::HTTP_CREATED);
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::REPORT_VIEW)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $year = $request->query->getInt('year', (int) date('Y'));

        $balances = $this->trialBalanceRepository->findByCompanyAndYear($company, $year);

        return $this->json(array_map(fn (TrialBalance $tb) => [
            'id' => (string) $tb->getId(),
            'year' => $tb->getYear(),
            'month' => $tb->getMonth(),
            'status' => $tb->getStatus(),
            'totalAccounts' => $tb->getTotalAccounts(),
            'originalFilename' => $tb->getOriginalFilename(),
            'sourceSoftware' => $tb->getSourceSoftware(),
            'error' => $tb->getError(),
            'processedAt' => $tb->getProcessedAt()?->format('c'),
            'createdAt' => $tb->getCreatedAt()?->format('c'),
        ], $balances));
    }

    #[Route('/{id}', methods: ['GET'])]
    public function show(Request $request, string $id): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::REPORT_VIEW)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $trialBalance = $this->trialBalanceRepository->find($id);
        if (!$trialBalance || $trialBalance->isDeleted() || $trialBalance->getCompany() !== $company) {
            return $this->json(['error' => 'Balance not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'id' => (string) $trialBalance->getId(),
            'year' => $trialBalance->getYear(),
            'month' => $trialBalance->getMonth(),
            'status' => $trialBalance->getStatus(),
            'totalAccounts' => $trialBalance->getTotalAccounts(),
            'originalFilename' => $trialBalance->getOriginalFilename(),
            'sourceSoftware' => $trialBalance->getSourceSoftware(),
            'error' => $trialBalance->getError(),
            'processedAt' => $trialBalance->getProcessedAt()?->format('c'),
            'createdAt' => $trialBalance->getCreatedAt()?->format('c'),
        ]);
    }

    #[Route('/{id}', methods: ['DELETE'])]
    public function delete(Request $request, string $id): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::REPORT_VIEW)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $trialBalance = $this->trialBalanceRepository->find($id);
        if (!$trialBalance || $trialBalance->isDeleted() || $trialBalance->getCompany() !== $company) {
            return $this->json(['error' => 'Balance not found.'], Response::HTTP_NOT_FOUND);
        }

        $trialBalance->softDelete($this->getUser());
        $this->entityManager->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/rows', methods: ['GET'], priority: 1)]
    public function rows(Request $request, string $id): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::REPORT_VIEW)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $trialBalance = $this->trialBalanceRepository->find($id);
        if (!$trialBalance || $trialBalance->isDeleted() || $trialBalance->getCompany() !== $company) {
            return $this->json(['error' => 'Balance not found.'], Response::HTTP_NOT_FOUND);
        }

        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT account_code, account_name, initial_debit, initial_credit,
                    previous_debit, previous_credit, current_debit, current_credit,
                    total_debit, total_credit, final_debit, final_credit
             FROM trial_balance_row WHERE trial_balance_id = :id
             ORDER BY account_code',
            ['id' => $id]
        );

        return $this->json([
            'id' => $id,
            'status' => $trialBalance->getStatus(),
            'totalAccounts' => $trialBalance->getTotalAccounts(),
            'rows' => $rows,
        ]);
    }

    #[Route('/{id}/reprocess', methods: ['POST'], priority: 1)]
    public function reprocess(Request $request, string $id): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::REPORT_VIEW)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $trialBalance = $this->trialBalanceRepository->find($id);
        if (!$trialBalance || $trialBalance->isDeleted() || $trialBalance->getCompany() !== $company) {
            return $this->json(['error' => 'Balance not found.'], Response::HTTP_NOT_FOUND);
        }

        // Reset status to pending and clear existing rows
        $this->entityManager->createQuery('DELETE FROM App\Entity\TrialBalanceRow r WHERE r.trialBalance = :tb')
            ->setParameter('tb', $trialBalance)
            ->execute();

        $trialBalance->setStatus('pending');
        $trialBalance->setTotalAccounts(0);
        $trialBalance->setError(null);
        $trialBalance->setProcessedAt(null);
        $this->entityManager->flush();

        // Re-dispatch for processing
        $this->messageBus->dispatch(new ProcessTrialBalanceMessage((string) $trialBalance->getId()));

        return $this->json([
            'id' => (string) $trialBalance->getId(),
            'status' => $trialBalance->getStatus(),
        ]);
    }

    #[Route('/analysis', methods: ['GET'], priority: 2)]
    public function analysis(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::REPORT_VIEW)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $org = $company->getOrganization();
        if (!$this->licenseManager->canViewReports($org)) {
            return $this->json([
                'error' => 'Reports are not available on your plan.',
                'code' => 'PLAN_LIMIT',
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        $year = $request->query->getInt('year', (int) date('Y'));
        if ($year < 2000 || $year > 2100) {
            return $this->json(['error' => 'Invalid year.'], Response::HTTP_BAD_REQUEST);
        }

        $report = $this->analysisService->analyze($company, $year);

        return $this->json($report);
    }
}
