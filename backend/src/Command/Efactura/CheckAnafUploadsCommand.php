<?php

namespace App\Command\Efactura;

use App\Entity\DocumentEvent;
use App\Enum\DocumentStatus;
use App\Event\Invoice\InvoiceRejectedEvent;
use App\Event\Invoice\InvoiceValidatedEvent;
use App\Exception\AnafRateLimitException;
use App\Enum\MessageKey;
use App\Repository\InvoiceRepository;
use App\Repository\OrganizationMembershipRepository;
use App\Service\Anaf\AnafTokenResolver;
use App\Service\Anaf\EFacturaClient;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AsCommand(
    name: 'app:efactura:check-status',
    description: 'Check status of outgoing invoices sent to ANAF',
)]
class CheckAnafUploadsCommand extends Command
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly EFacturaClient $eFacturaClient,
        private readonly AnafTokenResolver $tokenResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly NotificationService $notificationService,
        private readonly OrganizationMembershipRepository $membershipRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $invoices = $this->invoiceRepository->findBy(
            ['status' => DocumentStatus::SENT_TO_PROVIDER],
            ['createdAt' => 'ASC'],
            200 // Process max 200 per run
        );

        if (empty($invoices)) {
            $io->info('No invoices pending status check.');
            return Command::SUCCESS;
        }

        $io->text(sprintf('Checking %d invoices...', count($invoices)));
        $updated = 0;
        $batchCount = 0;

        foreach ($invoices as $invoice) {
            $uploadId = $invoice->getAnafUploadId();
            if (!$uploadId) {
                continue;
            }

            try {
                $company = $invoice->getCompany();
            } catch (\Doctrine\ORM\EntityNotFoundException) {
                // Company was soft-deleted, skip
                continue;
            }

            $token = $this->tokenResolver->resolve($company);
            if (!$token) {
                $this->logger->warning('No token for company during status check', [
                    'company' => $company->getName(),
                ]);
                continue;
            }

            try {
                // Refresh to detect if the message handler already processed this invoice
                $this->entityManager->refresh($invoice);
                if ($invoice->getStatus() !== DocumentStatus::SENT_TO_PROVIDER) {
                    continue;
                }

                $statusResponse = $this->eFacturaClient->checkStatus($uploadId, $token);

                if ($statusResponse->isOk()) {
                    $previousStatus = $invoice->getStatus();
                    $invoice->setStatus(DocumentStatus::VALIDATED);
                    $invoice->setAnafStatus('validated');
                    if ($statusResponse->downloadId) {
                        $invoice->setAnafDownloadId($statusResponse->downloadId);
                    }

                    $event = new DocumentEvent();
                    $event->setPreviousStatus($previousStatus);
                    $event->setNewStatus(DocumentStatus::VALIDATED);
                    $event->setMetadata(['action' => 'anaf_validated', 'uploadId' => $uploadId]);
                    $invoice->addEvent($event);
                    $updated++;
                    $batchCount++;

                    $this->eventDispatcher->dispatch(new InvoiceValidatedEvent($invoice), InvoiceValidatedEvent::NAME);
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
                } elseif ($statusResponse->isError()) {
                    // Try to get actual error: from response first, then by downloading ANAF error ZIP
                    $errorMsg = $statusResponse->errorMessage;
                    if (!$errorMsg && $statusResponse->downloadId && $token) {
                        $errorMsg = $this->downloadErrorDetails($statusResponse->downloadId, $token);
                    }
                    $errorMsg = $errorMsg ?: null;

                    $previousStatus = $invoice->getStatus();
                    $invoice->setStatus(DocumentStatus::REJECTED);
                    $invoice->setAnafStatus('rejected');
                    $invoice->setAnafErrorMessage($errorMsg);
                    if ($statusResponse->downloadId) {
                        $invoice->setAnafDownloadId($statusResponse->downloadId);
                    }

                    $event = new DocumentEvent();
                    $event->setPreviousStatus($previousStatus);
                    $event->setNewStatus(DocumentStatus::REJECTED);
                    $event->setMetadata([
                        'action' => 'anaf_rejected',
                        'uploadId' => $uploadId,
                        'error' => $errorMsg,
                    ]);
                    $invoice->addEvent($event);
                    $updated++;
                    $batchCount++;

                    $this->eventDispatcher->dispatch(new InvoiceRejectedEvent($invoice), InvoiceRejectedEvent::NAME);
                    $rejError = $errorMsg ?? 'Unknown error';
                    $companyName = $invoice->getCompany()?->getName() ?? '—';
                    $amount = $this->formatAmount($invoice->getTotal(), $invoice->getCurrency());
                    $this->notifyOrgMembers(
                        $invoice,
                        'invoice.rejected',
                        sprintf('%s — invoice rejected by ANAF', $companyName),
                        sprintf('Invoice %s · %s was rejected: %s', $invoice->getNumber(), $amount, $rejError),
                        MessageKey::MSG_INVOICE_REJECTED,
                        [
                            'company' => $companyName,
                            'number' => $invoice->getNumber(),
                            'amount' => $amount,
                            'error' => $rejError,
                        ],
                        MessageKey::TITLE_INVOICE_REJECTED,
                        ['company' => $companyName],
                    );
                }
                // isPending → do nothing, check again later

                if ($batchCount > 0 && $batchCount % 50 === 0) {
                    $this->entityManager->flush();
                }
            } catch (AnafRateLimitException $e) {
                $this->logger->warning('ANAF rate limit reached, stopping status checks', [
                    'limit' => $e->limitName,
                    'retryAfter' => $e->retryAfter,
                ]);
                $io->warning('ANAF rate limit reached. Remaining invoices will be checked on next run.');
                break;
            } catch (\Throwable $e) {
                $this->logger->error('Error checking ANAF status', [
                    'invoiceId' => $invoice->getId(),
                    'uploadId' => $uploadId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->entityManager->flush();
        $io->success(sprintf('Updated %d invoices.', $updated));

        return Command::SUCCESS;
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

    private function downloadErrorDetails(string $downloadId, string $token): ?string
    {
        try {
            $zipData = $this->eFacturaClient->download($downloadId, $token);

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
