<?php

namespace App\Service\EInvoice\Anaf;

use App\Entity\DocumentEvent;
use App\Entity\EInvoiceSubmission;
use App\Enum\DocumentStatus;
use App\Enum\EInvoiceSubmissionStatus;
use App\Message\EInvoice\CheckEInvoiceStatusMessage;
use App\Service\Anaf\AnafTokenResolver;
use App\Service\Anaf\EFacturaClient;
use App\Service\EInvoice\EInvoiceStatusCheckerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

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
            return;
        }

        if ($statusResponse->isError()) {
            $submission->setStatus(EInvoiceSubmissionStatus::REJECTED);
            $submission->setErrorMessage($statusResponse->errorMessage);

            $previousStatus = $invoice->getStatus();
            $invoice->setAnafStatus('nok');
            $invoice->setAnafErrorMessage($statusResponse->errorMessage);
            $invoice->setStatus(DocumentStatus::REJECTED);

            $event = new DocumentEvent();
            $event->setPreviousStatus($previousStatus);
            $event->setNewStatus(DocumentStatus::REJECTED);
            $event->setMetadata(['action' => 'anaf_rejected', 'error' => $statusResponse->errorMessage]);
            $invoice->addEvent($event);

            $this->entityManager->flush();
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
}
