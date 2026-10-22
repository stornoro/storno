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

        $q = $request->query;
        $year = $q->getInt('year', (int) date('Y'));
        if ($year < 2000 || $year > 2100) {
            return $this->json(['error' => 'Invalid year.'], Response::HTTP_BAD_REQUEST);
        }

        // Period: an explicit range, a quarter, the whole year, or (the default) a month
        if ($q->has('from') || $q->has('to')) {
            $from = self::parseDate($q->get('from'));
            $to = self::parseDate($q->get('to'));
            if ($from === null || $to === null || $from > $to) {
                return $this->json(['error' => 'Invalid date range: from and to must be YYYY-MM-DD, from <= to.'], Response::HTTP_BAD_REQUEST);
            }
            $period = sprintf('%s..%s', $from->format('Y-m-d'), $to->format('Y-m-d'));
        } elseif ($q->has('quarter')) {
            $quarter = $q->getInt('quarter');
            if ($quarter < 1 || $quarter > 4) {
                return $this->json(['error' => 'Invalid quarter.'], Response::HTTP_BAD_REQUEST);
            }
            $from = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, ($quarter - 1) * 3 + 1));
            $to = $from->modify('+3 months -1 day');
            $period = sprintf('%04d-Q%d', $year, $quarter);
        } elseif ($q->get('period') === 'year') {
            $from = new \DateTimeImmutable(sprintf('%04d-01-01', $year));
            $to = new \DateTimeImmutable(sprintf('%04d-12-31', $year));
            $period = (string) $year;
        } else {
            $month = $q->getInt('month', (int) date('m'));
            if ($month < 1 || $month > 12) {
                return $this->json(['error' => 'Invalid month.'], Response::HTTP_BAD_REQUEST);
            }
            $from = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
            $to = $from->modify('last day of this month');
            $period = sprintf('%04d-%02d', $year, $month);
        }

        $direction = $q->get('direction');
        if ($direction !== null && $direction !== '' && !in_array($direction, ['outgoing', 'incoming'], true)) {
            return $this->json(['error' => 'Invalid direction: outgoing or incoming.'], Response::HTTP_BAD_REQUEST);
        }
        $documentType = $q->get('documentType');
        if ($documentType !== null && $documentType !== '' && !in_array($documentType, ['invoice', 'credit_note'], true)) {
            return $this->json(['error' => 'Invalid documentType: invoice or credit_note.'], Response::HTTP_BAD_REQUEST);
        }
        $currency = strtoupper(trim((string) $q->get('currency', '')));
        if ($currency !== '' && !preg_match('/^[A-Z]{3}$/', $currency)) {
            return $this->json(['error' => 'Invalid currency.'], Response::HTTP_BAD_REQUEST);
        }
        $vatRate = $q->get('vatRate');
        if ($vatRate !== null && $vatRate !== '' && !is_numeric($vatRate)) {
            return $this->json(['error' => 'Invalid vatRate.'], Response::HTTP_BAD_REQUEST);
        }

        $report = $this->vatReportService->generateFor($company, [
            'from' => $from,
            'to' => $to,
            'period' => $period,
            'direction' => $direction ?: null,
            'clientId' => $q->get('clientId') ?: null,
            'supplierId' => $q->get('supplierId') ?: null,
            'currency' => $currency ?: null,
            'vatRate' => $vatRate !== '' ? $vatRate : null,
            'documentType' => $documentType ?: null,
            'finalOnly' => $q->getBoolean('finalOnly', false),
        ]);

        return $this->json($report);
    }

    private static function parseDate(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * The quarterly OSS (One Stop Shop) view of the VAT charged to EU consumers,
     * in euro at the quarter-end ECB rate, as declared in the special VAT return (D398).
     */
    #[Route('/reports/vat/oss', methods: ['GET'])]
    public function vatOssReport(Request $request): JsonResponse
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
        $quarter = $request->query->getInt('quarter', (int) ceil((int) date('n') / 3));

        if ($quarter < 1 || $quarter > 4) {
            return $this->json(['error' => 'Invalid quarter.'], Response::HTTP_BAD_REQUEST);
        }

        if ($year < 2000 || $year > 2100) {
            return $this->json(['error' => 'Invalid year.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($this->vatReportService->generateOss($company, $year, $quarter));
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
