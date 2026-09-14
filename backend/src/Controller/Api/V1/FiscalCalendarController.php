<?php

namespace App\Controller\Api\V1;

use App\Entity\Company;
use App\Repository\CompanyRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Service\Calendar\FiscalCalendarService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The fiscal deadlines derived from the company's profile and invoices, for the next `days`
 * days (default 60, at most 366) starting at `from` (default today), plus the unfiled ones of the
 * last month. `GET /all` gives the same across every company the caller can see.
 */
#[Route('/api/v1/fiscal-calendar')]
class FiscalCalendarController extends AbstractController
{
    public function __construct(
        private readonly FiscalCalendarService $calendar,
        private readonly OrganizationContext $organizationContext,
        private readonly CompanyRepository $companyRepository,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }
        [$from, $days, $error] = $this->window($request);
        if ($error !== null) {
            return $this->json(['error' => $error], Response::HTTP_BAD_REQUEST);
        }

        $items = $this->calendar->upcoming($company, $from, $days);

        return $this->json([
            'data' => $items,
            'from' => $from->format('Y-m-d'),
            'days' => $days,
            'company' => $this->companySummary($company),
            'counts' => $this->counts($items),
        ]);
    }

    #[Route('/all', methods: ['GET'])]
    public function all(Request $request): JsonResponse
    {
        $organization = $this->organizationContext->getOrganization();
        if (!$organization) {
            return $this->json(['error' => 'Organization not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }
        [$from, $days, $error] = $this->window($request);
        if ($error !== null) {
            return $this->json(['error' => $error], Response::HTTP_BAD_REQUEST);
        }

        $items = [];
        $companies = [];
        foreach ($this->companyRepository->findByOrganizationAndMembership($organization, $this->organizationContext->getMembership()) as $company) {
            if ($company->getDeletedAt() !== null) {
                continue;
            }
            $summary = $this->companySummary($company);
            $companyItems = $this->calendar->upcoming($company, $from, $days);
            foreach ($companyItems as $item) {
                $items[] = $item + ['company' => $summary];
            }
            $companies[] = $summary + ['counts' => $this->counts($companyItems)];
        }
        usort($items, static fn (array $a, array $b) => [$a['dueDate'], $a['company']['name'], $a['code']] <=> [$b['dueDate'], $b['company']['name'], $b['code']]);

        return $this->json([
            'data' => $items,
            'companies' => $companies,
            'from' => $from->format('Y-m-d'),
            'days' => $days,
            'counts' => $this->counts($items),
        ]);
    }

    /** @return array{0: \DateTimeImmutable, 1: int, 2: ?string} */
    private function window(Request $request): array
    {
        $fromParam = $request->query->get('from');
        $from = new \DateTimeImmutable('today');
        if (is_string($fromParam) && $fromParam !== '') {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $fromParam);
            if (!$parsed || $parsed->format('Y-m-d') !== $fromParam) {
                return [$from, 0, 'from must be a date formatted YYYY-MM-DD.'];
            }
            $from = $parsed;
        }
        $days = $request->query->getInt('days', 60);
        if ($days < 1 || $days > 366) {
            return [$from, 0, 'days must be between 1 and 366.'];
        }

        return [$from, $days, null];
    }

    /** @return array{id: string, name: ?string, cif: int, isIndividual: bool} */
    private function companySummary(Company $company): array
    {
        return [
            'id' => $company->getId()->toRfc4122(),
            'name' => $company->getName(),
            'cif' => $company->getCif(),
            'isIndividual' => $company->isIndividual(),
        ];
    }

    /** @param list<array<string, mixed>> $items @return array{due: int, overdue: int, filed: int} */
    private function counts(array $items): array
    {
        $counts = ['due' => 0, 'overdue' => 0, 'filed' => 0];
        foreach ($items as $item) {
            $counts[$item['status']] = ($counts[$item['status']] ?? 0) + 1;
        }

        return $counts;
    }
}
