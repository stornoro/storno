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

            $companyName = $invoice->getCompany()?->getName() ?? '—';
            $amount = $this->formatAmount($invoice->getTotal(), $invoice->getCurrency());
            $this->notifyOrgMembers(
                $invoice,
                'invoice.validated',
                sprintf('%s — invoice validated by ANAF', $companyName),
                sprintf('Invoice %s · %s has been validated by ANAF', $invoice->getNumber(), $amount),
                MessageKey::MSG_INVOICE_VALIDATED,
                [
                    'company' => $companyName,
                    'number' => $invoice->getNumber(),
                    'amount' => $amount,
                ],
                MessageKey::TITLE_INVOICE_VALIDATED,
                ['company' => $companyName],
            );

            return;
        }

        if ($statusResponse->isError()) {
            $submission->setStatus(EInvoiceSubmissionStatus::REJECTED);

            // Try to get the actual error: from the response first, then by downloading the error ZIP
            $errorMsg = $statusResponse->errorMessage;
            if (!$errorMsg && $statusResponse->downloadId && $token) {
                $errorMsg = $this->downloadErrorDetails($statusResponse->downloadId, $token);
            }
            $errorMsg = $errorMsg ?: null;

            $submission->setErrorMessage($errorMsg);

            $previousStatus = $invoice->getStatus();
            $invoice->setAnafStatus('nok');
            $invoice->setAnafErrorMessage($errorMsg);
            if ($statusResponse->downloadId) {
                $invoice->setAnafDownloadId($statusResponse->downloadId);
            }
            $invoice->setStatus(DocumentStatus::REJECTED);

            $event = new DocumentEvent();
            $event->setPreviousStatus($previousStatus);
            $event->setNewStatus(DocumentStatus::REJECTED);
            $event->setMetadata(['action' => 'anaf_rejected', 'error' => $errorMsg]);
            $invoice->addEvent($event);

            $this->entityManager->flush();

            $this->eventDispatcher->dispatch(new InvoiceRejectedEvent($invoice), InvoiceRejectedEvent::NAME);
            $this->publishInvoiceChange($invoice, 'invoice.rejected');

            $notifyError = $errorMsg ?? 'Unknown error';
            $companyName = $invoice->getCompany()?->getName() ?? '—';
            $amount = $this->formatAmount($invoice->getTotal(), $invoice->getCurrency());
            $this->notifyOrgMembers(
                $invoice,
                'invoice.rejected',
                sprintf('%s — invoice rejected by ANAF', $companyName),
                sprintf('Invoice %s · %s was rejected: %s', $invoice->getNumber(), $amount, $notifyError),
                MessageKey::MSG_INVOICE_REJECTED,
                [
                    'company' => $companyName,
                    'number' => $invoice->getNumber(),
                    'amount' => $amount,
                    'error' => $notifyError,
                ],
                MessageKey::TITLE_INVOICE_REJECTED,
                ['company' => $companyName],
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

    private function notifyOrgMembers(\App\Entity\Invoice $invoice, string $type, string $title, string $message, string $messageKey = '', array $messageParams = [], string $titleKey = '', array $titleParams = []): void
    {
        try {
            $company = $invoice->getCompany();
            $users = $this->membershipRepository->findActiveUsersByCompany($company);

            $data = [
                'invoiceId' => $invoice->getId()->toRfc4122(),
                'invoiceNumber' => $invoice->getNumber(),
                'companyId' => $company->getId()->toRfc4122(),
                'companyName' => $company->getName(),
            ];
            if ($messageKey) {
                $data['messageKey'] = $messageKey;
                $data['messageParams'] = $messageParams;
            }
            if ($titleKey) {
                $data['titleKey'] = $titleKey;
            }
            if (!empty($titleParams)) {
                $data['titleParams'] = $titleParams;
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

    private function formatAmount(string $total, string $currency): string
    {
        return number_format((float) $total, 2, '.', ',') . ' ' . $currency;
    }

    /**
     * Download and parse the ANAF error ZIP to extract the actual error message.
     */
    private function downloadErrorDetails(string $downloadId, string $token): ?string
    {
        try {
            $zipData = $this->client->download($downloadId, $token);

            $tempFile = tempnam(sys_get_temp_dir(), 'anaf-err');
            file_put_contents($tempFile, $zipData);

            $zip = new \ZipArchive();
            if ($zip->open($tempFile) !== true) {
                unlink($tempFile);
                return null;
            }

            $errors = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $content = $zip->getFromIndex($i);
                if ($content === false) {
                    continue;
                }

                $xml = @simplexml_load_string($content);
                if ($xml === false) {
                    $trimmed = trim($content);
                    if ($trimmed !== '') {
                        $errors[] = $trimmed;
                    }
                    continue;
                }

                $this->extractErrorsFromXml($xml, $errors);
            }

            $zip->close();
            unlink($tempFile);

            return empty($errors) ? null : implode("\n", array_unique($errors));
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to download ANAF error details', [
                'downloadId' => $downloadId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function extractErrorsFromXml(\SimpleXMLElement $xml, array &$errors): void
    {
        foreach ($xml->attributes() as $name => $value) {
            if (strtolower((string) $name) === 'errormessage' && (string) $value !== '') {
                $errors[] = (string) $value;
            }
        }

        $nodeName = strtolower($xml->getName());
        if (str_contains($nodeName, 'error') && trim((string) $xml) !== '' && count($xml->children()) === 0) {
            $errors[] = trim((string) $xml);
        }

        foreach ($xml->children() as $child) {
            $this->extractErrorsFromXml($child, $errors);
        }

        foreach ($xml->getNamespaces(true) as $ns) {
            foreach ($xml->children($ns) as $child) {
                $this->extractErrorsFromXml($child, $errors);
            }
        }
    }
}
