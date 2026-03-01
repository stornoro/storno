<?php

namespace App\Command\Efactura;

use App\Entity\DocumentEvent;
use App\Enum\DocumentStatus;
use App\Exception\AnafRateLimitException;
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

                    $this->notifyOrgMembers($invoice, 'invoice.validated', 'Factură validată ANAF', sprintf(
                        'Factura %s a fost validată de ANAF',
                        $invoice->getNumber(),
                    ));
                } elseif ($statusResponse->isError()) {
                    $previousStatus = $invoice->getStatus();
                    $invoice->setStatus(DocumentStatus::REJECTED);
                    $invoice->setAnafStatus('rejected');
                    $invoice->setAnafErrorMessage($statusResponse->errorMessage);
                    if ($statusResponse->downloadId) {
                        $invoice->setAnafDownloadId($statusResponse->downloadId);
                    }

                    $event = new DocumentEvent();
                    $event->setPreviousStatus($previousStatus);
                    $event->setNewStatus(DocumentStatus::REJECTED);
                    $event->setMetadata([
                        'action' => 'anaf_rejected',
                        'uploadId' => $uploadId,
                        'error' => $statusResponse->errorMessage,
                    ]);
                    $invoice->addEvent($event);
                    $updated++;
                    $batchCount++;

                    $this->notifyOrgMembers($invoice, 'invoice.rejected', 'Factură respinsă ANAF', sprintf(
                        'Factura %s a fost respinsă de ANAF: %s',
                        $invoice->getNumber(),
                        $statusResponse->errorMessage ?? 'Eroare necunoscută',
                    ));
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

    private function notifyOrgMembers(\App\Entity\Invoice $invoice, string $type, string $title, string $message): void
    {
        try {
            $company = $invoice->getCompany();
            $users = $this->membershipRepository->findActiveUsersByCompany($company);

            foreach ($users as $user) {
                $this->notificationService->createNotification($user, $type, $title, $message, [
                    'invoiceId' => $invoice->getId()->toRfc4122(),
                    'invoiceNumber' => $invoice->getNumber(),
                    'companyId' => $company->getId()->toRfc4122(),
                ]);
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
