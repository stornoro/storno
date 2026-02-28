<?php

namespace App\MessageHandler;

use App\Message\RestoreBackupMessage;
use App\Repository\BackupJobRepository;
use App\Repository\CompanyRepository;
use App\Repository\UserRepository;
use App\Service\Backup\CompanyRestoreService;
use App\Service\Centrifugo\CentrifugoService;
use App\Service\CompanyDataPurger;
use App\Service\NotificationService;
use App\Service\Storage\OrganizationStorageResolver;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class RestoreBackupHandler
{
    public function __construct(
        private readonly BackupJobRepository $backupJobRepository,
        private readonly CompanyRepository $companyRepository,
        private readonly UserRepository $userRepository,
        private readonly CompanyRestoreService $restoreService,
        private readonly CompanyDataPurger $dataPurger,
        private readonly FilesystemOperator $defaultStorage,
        private readonly OrganizationStorageResolver $storageResolver,
        private readonly NotificationService $notificationService,
        private readonly CentrifugoService $centrifugo,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(RestoreBackupMessage $message): void
    {
        $job = $this->backupJobRepository->find($message->backupJobId);
        if (!$job) {
            $this->logger->warning('BackupJob not found for restore', ['jobId' => $message->backupJobId]);
            return;
        }

        $company = $this->companyRepository->find($message->companyId);
        if (!$company) {
            $this->logger->warning('Company not found for restore', ['companyId' => $message->companyId]);
            return;
        }

        $user = $this->userRepository->find($message->userId);

        $job->setStatus('processing');
        $job->setCurrentStep('Starting restore');
        $this->entityManager->flush();

        $channel = 'backup:company_' . $message->companyId;

        try {
            $storagePath = $job->getStoragePath();
            $storage = $this->storageResolver->resolveForCompany($company);
            if (!$storagePath || !$storage->fileExists($storagePath)) {
                throw new \RuntimeException('Backup ZIP file not found in storage');
            }

            $zipContent = $storage->read($storagePath);
            $conn = $this->entityManager->getConnection();

            // Optionally purge existing data first
            if ($message->purgeExisting) {
                $this->centrifugo->publish($channel, [
                    'event' => 'restore_progress',
                    'jobId' => (string) $job->getId(),
                    'progress' => 5,
                    'step' => 'Purging existing data',
                ]);

                $this->dataPurger->purge($conn, $message->companyId);
            }

            $entityCounts = $this->restoreService->restore(
                conn: $conn,
                targetCompanyId: $message->companyId,
                zipContent: $zipContent,
                progressCallback: function (int $percent, string $step) use ($job, $channel) {
                    // Scale progress: purge takes 0-10%, restore takes 10-100%
                    $scaledPercent = 10 + (int) ($percent * 0.9);
                    $job->setProgress($scaledPercent);
                    $job->setCurrentStep($step);
                    $this->entityManager->flush();

                    $this->centrifugo->publish($channel, [
                        'event' => 'restore_progress',
                        'jobId' => (string) $job->getId(),
                        'progress' => $scaledPercent,
                        'step' => $step,
                    ]);
                },
            );

            $job->setStatus('completed');
            $job->setProgress(100);
            $job->setCurrentStep(null);
            $job->setMetadata($entityCounts);
            $job->setCompletedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            // Cleanup the uploaded ZIP
            try {
                $storage->delete($storagePath);
            } catch (\Throwable) {
                // Non-critical
            }

            $this->centrifugo->publish($channel, [
                'event' => 'restore_completed',
                'jobId' => (string) $job->getId(),
                'entityCounts' => $entityCounts,
            ]);

            if ($user) {
                $this->notificationService->createNotification(
                    $user,
                    'restore_completed',
                    'Restaurare finalizata',
                    sprintf('Datele companiei %s au fost restaurate cu succes.', $company->getName()),
                    ['entityCounts' => $entityCounts],
                );
                $this->entityManager->flush();
            }

            $this->logger->info('Backup restored', [
                'jobId' => (string) $job->getId(),
                'companyId' => $message->companyId,
                'entityCounts' => $entityCounts,
            ]);
        } catch (\Throwable $e) {
            $job->setStatus('failed');
            $job->setErrorMessage($e->getMessage());
            $job->setCompletedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            $this->centrifugo->publish($channel, [
                'event' => 'backup_error',
                'jobId' => (string) $job->getId(),
                'error' => $e->getMessage(),
            ]);

            $this->logger->error('Backup restore failed', [
                'jobId' => (string) $job->getId(),
                'companyId' => $message->companyId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
