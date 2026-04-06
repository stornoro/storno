<?php

namespace App\Service\EInvoice\Anaf;

use App\Entity\DocumentEvent;
use App\Entity\EInvoiceSubmission;
use App\Enum\DocumentStatus;
use App\Enum\EInvoiceSubmissionStatus;
use App\Event\Invoice\InvoiceRejectedEvent;
use App\Event\Invoice\InvoiceValidatedEvent;
use App\Enum\MessageKey;
use App\Message\EInvoice\CheckEInvoiceStatusMessage;
use App\Repository\OrganizationMembershipRepository;
use App\Service\Anaf\AnafTokenResolver;
use App\Service\Anaf\EFacturaClient;
use App\Service\EInvoice\EInvoiceStatusCheckerInterface;
use App\Service\Centrifugo\CentrifugoService;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AutoconfigureTag('app.einvoice_status_checker', ['provider' => 'anaf'])]
final class AnafStatusChecker implements EInvoiceStatusCheckerInterface
{
    private const MAX_ATTEMPTS = 5;

    /**
     * Exponential backoff delays in milliseconds.
     * Keyed by attempt index (0-based).
     */
    private const DELAY_SCHEDULE_MS = [
        0 => 300_000,    // 5 min
        1 => 900_000,    // 15 min
        2 => 1_800_000,  // 30 min
        3 => 3_600_000,  // 1 hour
        4 => 7_200_000,  // 2 hours
    ];

    public function __construct(
        private readonly EFacturaClient $client,
        private readonly AnafTokenResolver $tokenResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly NotificationService $notificationService,
        private readonly OrganizationMembershipRepository $membershipRepository,
        private readonly CentrifugoService $centrifugo,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    public function check(EInvoiceSubmission $submission, CheckEInvoiceStatusMessage $message): void
    {
        if ($message->attempt >= self::MAX_ATTEMPTS) {
            $submission->setStatus(EInvoiceSubmissionStatus::ERROR);
            $submission->setErrorMessage('Numarul maxim de verificari a fost atins.');

            $invoice = $submission->getInvoice();
            if ($invoice !== null) {
                $invoice->setAnafStatus('pending_timeout');
                $invoice->setAnafErrorMessage('Numarul maxim de verificari a fost atins.');
            }
            $this->entityManager->flush();
            return;
        }

        $invoice = $submission->getInvoice();
        if ($invoice === null) {
            $this->logger->warning('AnafStatusChecker: Invoice not found for submission.', [
                'submissionId' => $message->submissionId,
            ]);
            return;
        }

        // Skip if the cron command already handled this invoice
        if ($invoice->getStatus() !== DocumentStatus::SENT_TO_PROVIDER) {
            $submission->setStatus(EInvoiceSubmissionStatus::ACCEPTED);
            $this->entityManager->flush();
            return;
        }

        $uploadId = $submission->getExternalId();
        if ($uploadId === null) {
            $this->logger->warning('AnafStatusChecker: No upload ID on submission.', [
                'submissionId' => $message->submissionId,
            ]);
            return;
        }

        $token = $this->tokenResolver->resolve($invoice->getCompany());
        if ($token === null) {
            $this->logger->error('AnafStatusChecker: No ANAF token available.', [
                'submissionId' => $message->submissionId,
            ]);
            return;
        }

        $statusResponse = $this->client->checkStatus($uploadId, $token);

        if ($statusResponse->isOk()) {
            $submission->setStatus(EInvoiceSubmissionStatus::ACCEPTED);
            $submission->setMetadata(array_merge($submission->getMetadata() ?? [], [
                'downloadId' => $statusResponse->downloadId,
                'lastCheckedAt' => (new \DateTimeImmutable())->format('c'),
            ]));

            $previousStatus = $invoice->getStatus();
            $invoice->setAnafDownloadId($statusResponse->downloadId);
            $invoice->setAnafStatus('ok');

            $isRefund = $invoice->getParentDocument() !== null;
            $newStatus = $isRefund ? DocumentStatus::REFUND : DocumentStatus::VALIDATED;
            $invoice->setStatus($newStatus);

            $event = new DocumentEvent();
            $event->setPreviousStatus($previousStatus);
            $event->setNewStatus($newStatus);
            $event->setMetadata(['action' => 'anaf_validated', 'downloadId' => $statusResponse->downloadId]);
            $invoice->addEvent($event);

            $this->entityManager->flush();

            $this->eventDispatcher->dispatch(new InvoiceValidatedEvent($invoice), InvoiceValidatedEvent::NAME);
            $this->publishInvoiceChange($invoice, 'invoice.validated');

            $this->notifyOrgMembers(
                $invoice,
                'invoice.validated',
                'Invoice validated by ANAF',
                sprintf('Invoice %s has been validated by ANAF', $invoice->getNumber()),
                MessageKey::MSG_INVOICE_VALIDATED,
                ['number' => $invoice->getNumber()],
                MessageKey::TITLE_INVOICE_VALIDATED,
            );

            return;
        }

        if ($statusResponse->isError()) {
            $submission->setStatus(EInvoiceSubmissionStatus::REJECTED);
            $submission->setErrorMessage($statusResponse->errorMessage);

            $previousStatus = $invoice->getStatus();
            $invoice->setAnafStatus('nok');
            $invoice->setAnafErrorMessage($statusResponse->errorMessage);
            if ($statusResponse->downloadId) {
                $invoice->setAnafDownloadId($statusResponse->downloadId);
            }
            $invoice->setStatus(DocumentStatus::REJECTED);

            $event = new DocumentEvent();
            $event->setPreviousStatus($previousStatus);
            $event->setNewStatus(DocumentStatus::REJECTED);
            $event->setMetadata(['action' => 'anaf_rejected', 'error' => $statusResponse->errorMessage]);
            $invoice->addEvent($event);

            $this->entityManager->flush();

            $this->eventDispatcher->dispatch(new InvoiceRejectedEvent($invoice), InvoiceRejectedEvent::NAME);
            $this->publishInvoiceChange($invoice, 'invoice.rejected');

            $errorMsg = $statusResponse->errorMessage ?? 'Unknown error';
            $this->notifyOrgMembers(
                $invoice,
                'invoice.rejected',
                'Invoice rejected by ANAF',
                sprintf('Invoice %s was rejected by ANAF: %s', $invoice->getNumber(), $errorMsg),
                MessageKey::MSG_INVOICE_REJECTED,
                ['number' => $invoice->getNumber(), 'error' => $errorMsg],
                MessageKey::TITLE_INVOICE_REJECTED,
            );

            return;
        }

        // Still pending — schedule next check with exponential backoff
        $delay = self::DELAY_SCHEDULE_MS[$message->attempt] ?? self::DELAY_SCHEDULE_MS[4];

        $this->messageBus->dispatch(
            new CheckEInvoiceStatusMessage(
                submissionId: $message->submissionId,
                attempt: $message->attempt + 1,
            ),
            [new DelayStamp($delay)]
        );
    }

    private function publishInvoiceChange(\App\Entity\Invoice $invoice, string $type): void
    {
        $company = $invoice->getCompany();
        if (!$company) {
            return;
        }

        $this->centrifugo->publish('invoices:company_' . $company->getId()->toRfc4122(), [
            'type' => $type,
        ]);
    }

    private function notifyOrgMembers(\App\Entity\Invoice $invoice, string $type, string $title, string $message, string $messageKey = '', array $messageParams = [], string $titleKey = ''): void
    {
        try {
            $company = $invoice->getCompany();
            $users = $this->membershipRepository->findActiveUsersByCompany($company);

            $data = [
                'invoiceId' => $invoice->getId()->toRfc4122(),
                'invoiceNumber' => $invoice->getNumber(),
                'companyId' => $company->getId()->toRfc4122(),
            ];
            if ($messageKey) {
                $data['messageKey'] = $messageKey;
                $data['messageParams'] = $messageParams;
            }
            if ($titleKey) {
                $data['titleKey'] = $titleKey;
            }

            foreach ($users as $user) {
                $this->notificationService->createNotification($user, $type, $title, $message, $data);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send notification for invoice status change', [
                'invoiceId' => $invoice->getId(),
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
