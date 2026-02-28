<?php

namespace App\Controller\Api\V1;

use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Service\LicenseManager;
use App\Service\Report\SalesAnalysisService;
use App\Service\Report\VatReportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1')]
class ReportController extends AbstractController
{
    public function __construct(
        private readonly OrganizationContext $organizationContext,
        private readonly VatReportService $vatReportService,
        private readonly SalesAnalysisService $salesAnalysisService,
        private readonly LicenseManager $licenseManager,
    ) {}

    #[Route('/reports/vat', methods: ['GET'])]
    public function vatReport(Request $request): JsonResponse
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
        $month = $request->query->getInt('month', (int) date('m'));

        if ($month < 1 || $month > 12) {
            return $this->json(['error' => 'Invalid month.'], Response::HTTP_BAD_REQUEST);
        }

        if ($year < 2000 || $year > 2100) {
            return $this->json(['error' => 'Invalid year.'], Response::HTTP_BAD_REQUEST);
        }

        $report = $this->vatReportService->generate($company, $year, $month);

        return $this->json($report);
    }

    #[Route('/reports/sales', methods: ['GET'])]
    public function salesAnalysis(Request $request): JsonResponse
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

        $dateFrom = $request->query->get('dateFrom');
        $dateTo = $request->query->get('dateTo');

        if (!$dateFrom || !$dateTo) {
            return $this->json(['error' => 'dateFrom and dateTo are required.'], Response::HTTP_BAD_REQUEST);
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            return $this->json(['error' => 'Invalid date format. Use YYYY-MM-DD.'], Response::HTTP_BAD_REQUEST);
        }

        if ($dateFrom > $dateTo) {
            return $this->json(['error' => 'dateFrom must be before or equal to dateTo.'], Response::HTTP_BAD_REQUEST);
        }

        $report = $this->salesAnalysisService->generate($company, $dateFrom, $dateTo);

        return $this->json($report);
    }
}
