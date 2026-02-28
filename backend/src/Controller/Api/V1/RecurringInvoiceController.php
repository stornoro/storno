<?php

namespace App\Controller\Api\V1;

use App\Manager\RecurringInvoiceManager;
use App\Repository\RecurringInvoiceRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Constants\Pagination;
use App\Service\LicenseManager;
use App\Service\RecurringInvoiceProcessor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1')]
class RecurringInvoiceController extends AbstractController
{
    public function __construct(
        private readonly RecurringInvoiceManager $manager,
        private readonly RecurringInvoiceRepository $repository,
        private readonly OrganizationContext $organizationContext,
        private readonly RecurringInvoiceProcessor $processor,
        private readonly EntityManagerInterface $entityManager,
        private readonly LicenseManager $licenseManager,
    ) {}

    #[Route('/recurring-invoices', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::RECURRING_INVOICE_VIEW)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $filters = $request->query->all();
        $page = $request->query->getInt('page', 1);
        $limit = Pagination::clamp($request->query->getInt('limit', Pagination::DEFAULT_LIMIT));

        $result = $this->manager->listByCompany($company, $filters, $page, $limit);

        return $this->json($result, context: ['groups' => ['recurring_invoice:list']]);
    }

    #[Route('/recurring-invoices/bulk-delete', methods: ['POST'])]
    public function bulkDelete(Request $request): JsonResponse
    {
        if (!$this->organizationContext->hasPermission(Permission::RECURRING_INVOICE_MANAGE)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];

        if (!is_array($ids) || count($ids) === 0 || count($ids) > 100) {
            return $this->json(['error' => 'Provide between 1 and 100 IDs.'], Response::HTTP_BAD_REQUEST);
        }

        $deleted = 0;
        $errors = [];

        foreach ($ids as $id) {
            $ri = $this->manager->find($id);
            if (!$ri) {
                $errors[] = ['id' => $id, 'error' => 'Recurring invoice not found.'];
                continue;
            }
            try {
                $this->manager->delete($ri);
                $deleted++;
            } catch (\Throwable $e) {
                $errors[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }

        return $this->json(['deleted' => $deleted, 'errors' => $errors]);
    }

    #[Route('/recurring-invoices/bulk-toggle-active', methods: ['POST'])]
    public function bulkToggleActive(Request $request): JsonResponse
    {
        if (!$this->organizationContext->hasPermission(Permission::RECURRING_INVOICE_MANAGE)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];
        $isActive = $data['isActive'] ?? null;

        if (!is_array($ids) || count($ids) === 0 || count($ids) > 100) {
            return $this->json(['error' => 'Provide between 1 and 100 IDs.'], Response::HTTP_BAD_REQUEST);
        }

        if ($isActive === null) {
            return $this->json(['error' => 'isActive is required.'], Response::HTTP_BAD_REQUEST);
        }

        $updated = 0;
        $errors = [];

        foreach ($ids as $id) {
            $ri = $this->manager->find($id);
            if (!$ri) {
                $errors[] = ['id' => $id, 'error' => 'Recurring invoice not found.'];
                continue;
            }
            try {
                $ri->setIsActive((bool) $isActive);
                $updated++;
            } catch (\Throwable $e) {
                $errors[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }

        $this->entityManager->flush();

        return $this->json(['updated' => $updated, 'errors' => $errors]);
    }

    #[Route('/recurring-invoices/bulk-issue-now', methods: ['POST'])]
    public function bulkIssueNow(Request $request): JsonResponse
    {
        if (!$this->organizationContext->hasPermission(Permission::RECURRING_INVOICE_MANAGE)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];

        if (!is_array($ids) || count($ids) === 0 || count($ids) > 100) {
            return $this->json(['error' => 'Provide between 1 and 100 IDs.'], Response::HTTP_BAD_REQUEST);
        }

        $issued = 0;
        $errors = [];

        foreach ($ids as $id) {
            $ri = $this->manager->find($id);
            if (!$ri) {
                $errors[] = ['id' => $id, 'error' => 'Recurring invoice not found.'];
                continue;
            }
            if (!$ri->isActive()) {
                $errors[] = ['id' => $id, 'error' => 'Recurring invoice is not active.'];
                continue;
            }
            if (!$ri->getCompany()) {
                $errors[] = ['id' => $id, 'error' => 'Recurring invoice has no company.'];
                continue;
            }
            try {
                $this->processor->issueNow($ri, $this->getUser());
                $issued++;
            } catch (\Throwable $e) {
                $errors[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }

        return $this->json(['issued' => $issued, 'errors' => $errors]);
    }

    #[Route('/recurring-invoices/{uuid}', methods: ['GET'])]
    public function show(string $uuid): JsonResponse
    {
        if (!$this->organizationContext->hasPermission(Permission::RECURRING_INVOICE_VIEW)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $ri = $this->manager->find($uuid);
        if (!$ri) {
            return $this->json(['error' => 'Recurring invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($ri, context: ['groups' => ['recurring_invoice:detail']]);
    }

    #[Route('/recurring-invoices', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::RECURRING_INVOICE_MANAGE)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $org = $company->getOrganization();
        if (!$this->licenseManager->canUseRecurringInvoices($org)) {
            return $this->json([
                'error' => 'Recurring invoices are not available on your plan.',
                'code' => 'PLAN_LIMIT',
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        $data = json_decode($request->getContent(), true);

        // Validation
        $validationError = $this->validateData($data);
        if ($validationError) {
            return $this->json(['error' => $validationError], Response::HTTP_BAD_REQUEST);
        }

        try {
            $ri = $this->manager->create($company, $data);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($ri, Response::HTTP_CREATED, [], ['groups' => ['recurring_invoice:detail']]);
    }

    #[Route('/recurring-invoices/{uuid}', methods: ['PUT'])]
    public function update(string $uuid, Request $request): JsonResponse
    {
        if (!$this->organizationContext->hasPermission(Permission::RECURRING_INVOICE_MANAGE)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $ri = $this->manager->find($uuid);
        if (!$ri) {
            return $this->json(['error' => 'Recurring invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        if (!empty($data['nextIssuanceDate'])) {
            $nextDate = \DateTimeImmutable::createFromFormat('Y-m-d', $data['nextIssuanceDate']);
            if ($nextDate && $nextDate->format('Y-m-d') < (new \DateTimeImmutable('today'))->format('Y-m-d')) {
                return $this->json(['error' => 'nextIssuanceDate cannot be in the past.'], Response::HTTP_BAD_REQUEST);
            }
        }

        try {
            $ri = $this->manager->update($ri, $data);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($ri, context: ['groups' => ['recurring_invoice:detail']]);
    }

    #[Route('/recurring-invoices/{uuid}', methods: ['DELETE'])]
    public function delete(string $uuid): JsonResponse
    {
        if (!$this->organizationContext->hasPermission(Permission::RECURRING_INVOICE_MANAGE)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $ri = $this->manager->find($uuid);
        if (!$ri) {
            return $this->json(['error' => 'Recurring invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->manager->delete($ri);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/recurring-invoices/{uuid}/toggle', methods: ['POST'])]
    public function toggle(string $uuid): JsonResponse
    {
        if (!$this->organizationContext->hasPermission(Permission::RECURRING_INVOICE_MANAGE)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $ri = $this->manager->find($uuid);
        if (!$ri) {
            return $this->json(['error' => 'Recurring invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        $ri = $this->manager->toggle($ri);

        return $this->json($ri, context: ['groups' => ['recurring_invoice:detail']]);
    }

    #[Route('/recurring-invoices/{uuid}/issue-now', methods: ['POST'])]
    public function issueNow(string $uuid): JsonResponse
    {
        if (!$this->organizationContext->hasPermission(Permission::RECURRING_INVOICE_MANAGE)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $ri = $this->manager->find($uuid);
        if (!$ri) {
            return $this->json(['error' => 'Recurring invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$ri->getCompany()) {
            return $this->json(['error' => 'Recurring invoice has no company.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->processor->issueNow($ri, $this->getUser());
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($result);
    }

    private function validateData(array $data): ?string
    {
        // Frequency validation
        $validFrequencies = ['once', 'weekly', 'monthly', 'bimonthly', 'quarterly', 'semi_annually', 'yearly'];
        if (!isset($data['frequency']) || !in_array($data['frequency'], $validFrequencies, true)) {
            return 'Frequency must be one of: ' . implode(', ', $validFrequencies) . '.';
        }

        // FrequencyDay validation
        if (!isset($data['frequencyDay'])) {
            return 'frequencyDay is required.';
        }

        $day = (int) $data['frequencyDay'];
        if ($data['frequency'] === 'weekly' && ($day < 1 || $day > 7)) {
            return 'frequencyDay must be 1-7 for weekly frequency.';
        }
        $monthBasedFrequencies = ['once', 'monthly', 'bimonthly', 'quarterly', 'semi_annually', 'yearly'];
        if (in_array($data['frequency'], $monthBasedFrequencies, true) && ($day < 1 || $day > 28)) {
            return 'frequencyDay must be 1-28 for ' . $data['frequency'] . ' frequency.';
        }
        if ($data['frequency'] === 'yearly') {
            if (!isset($data['frequencyMonth']) || (int) $data['frequencyMonth'] < 1 || (int) $data['frequencyMonth'] > 12) {
                return 'frequencyMonth (1-12) is required for yearly frequency.';
            }
        }

        // Lines validation
        if (empty($data['lines']) || !is_array($data['lines'])) {
            return 'At least one line is required.';
        }

        foreach ($data['lines'] as $i => $line) {
            if (empty($line['description'])) {
                return "Line $i: description is required.";
            }
            if (!isset($line['quantity']) || (float) $line['quantity'] <= 0) {
                return "Line $i: quantity must be positive.";
            }
            if (!isset($line['unitPrice']) || (float) $line['unitPrice'] < 0) {
                return "Line $i: unitPrice must be non-negative.";
            }
            if (isset($line['markupPercent']) && $line['markupPercent'] !== null && (float) $line['markupPercent'] < 0) {
                return "Line $i: markupPercent must be non-negative.";
            }
        }

        // nextIssuanceDate
        if (empty($data['nextIssuanceDate'])) {
            return 'nextIssuanceDate is required.';
        }

        $nextDate = \DateTimeImmutable::createFromFormat('Y-m-d', $data['nextIssuanceDate']);
        if ($nextDate && $nextDate->format('Y-m-d') < (new \DateTimeImmutable('today'))->format('Y-m-d')) {
            return 'nextIssuanceDate cannot be in the past.';
        }

        // Auto-email validation
        if (!empty($data['autoEmailTime']) && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $data['autoEmailTime'])) {
            return 'autoEmailTime must be in HH:MM format.';
        }

        // Penalty validation
        if (isset($data['penaltyPercentPerDay']) && $data['penaltyPercentPerDay'] !== null && (float) $data['penaltyPercentPerDay'] < 0) {
            return 'penaltyPercentPerDay must be non-negative.';
        }
        if (isset($data['penaltyGraceDays']) && $data['penaltyGraceDays'] !== null && (int) $data['penaltyGraceDays'] < 0) {
            return 'penaltyGraceDays must be non-negative.';
        }

        return null;
    }
}
