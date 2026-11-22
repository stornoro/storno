<?php

namespace App\Controller\Api\V1;

use App\Entity\User;
use App\Repository\CompanyRepository;
use App\Service\LicenseManager;
use App\Service\OverdueReminderService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Reminder backlog: overdue invoices that passed a reminder step before the daily run could
 * send it. Counted with dryRun, mailed on request as one statement per client.
 */
#[Route('/api/v1/companies')]
class OverdueReminderBacklogController extends AbstractController
{
    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly OverdueReminderService $reminderService,
        private readonly LicenseManager $licenseManager,
        private readonly LockFactory $lockFactory,
    ) {}

    #[Route('/{uuid}/overdue-reminders/backlog', methods: ['POST'])]
    public function backlog(string $uuid, Request $request): JsonResponse
    {
        $company = Uuid::isValid($uuid) ? $this->companyRepository->find(Uuid::fromString($uuid)) : null;
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }
        $this->denyAccessUnlessGranted('COMPANY_EDIT', $company);

        $data = json_decode($request->getContent(), true) ?: [];
        if (array_key_exists('dryRun', $data) && !is_bool($data['dryRun'])) {
            return $this->json(['error' => 'dryRun must be true or false.'], Response::HTTP_BAD_REQUEST);
        }
        $dryRun = $data['dryRun'] ?? false;

        if (!$company->isOverdueRemindersEnabled()) {
            return $this->json(
                ['error' => 'Automatic overdue reminders are off for this company.', 'code' => 'REMINDERS_OFF'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ($dryRun) {
            return $this->json($this->reminderService->sendBacklog($company, null, true));
        }

        $org = $company->getOrganization();
        if ($org && !$this->licenseManager->canSendEmails($org)) {
            return $this->json(['error' => 'Email sending is not available on your plan.', 'code' => 'PLAN_LIMIT'], Response::HTTP_PAYMENT_REQUIRED);
        }

        // Two clicks in a row must not mail every client twice
        $lock = $this->lockFactory->createLock('overdue-reminder-backlog-' . $company->getId(), 600);
        if (!$lock->acquire()) {
            return $this->json(['error' => 'The backlog is already being sent.', 'code' => 'ALREADY_RUNNING'], Response::HTTP_CONFLICT);
        }

        try {
            /** @var User|null $user */
            $user = $this->getUser();

            return $this->json($this->reminderService->sendBacklog($company, $user instanceof User ? $user : null));
        } finally {
            $lock->release();
        }
    }
}
