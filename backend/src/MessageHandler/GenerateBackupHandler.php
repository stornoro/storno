<?php

namespace App\MessageHandler;

use App\Message\GenerateBackupMessage;
use App\Repository\BackupJobRepository;
use App\Repository\CompanyRepository;
use App\Repository\UserRepository;
use App\Service\Backup\CompanyBackupService;
use App\Service\Centrifugo\CentrifugoService;
use App\Service\NotificationService;
use App\Service\Storage\OrganizationStorageResolver;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class GenerateBackupHandler
{
    public function __construct(
        private readonly BackupJobRepository $backupJobRepository,
        private readonly CompanyRepository $companyRepository,
        private readonly UserRepository $userRepository,
        private readonly CompanyBackupService $backupService,
        private readonly FilesystemOperator $defaultStorage,
        private readonly OrganizationStorageResolver $storageResolver,
        private readonly NotificationService $notificationService,
        private readonly CentrifugoService $centrifugo,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(GenerateBackupMessage $message): void
    {
        $job = $this->backupJobRepository->find($message->backupJobId);
        if (!$job) {
            $this->logger->warning('BackupJob not found', ['jobId' => $message->backupJobId]);
            return;
        }

        $company = $this->companyRepository->find($message->companyId);
        if (!$company) {
            $this->logger->warning('Company not found for backup', ['companyId' => $message->companyId]);
            return;
        }

        $user = $this->userRepository->find($message->userId);

        $job->setStatus('processing');
        $job->setCurrentStep('Starting backup');
        $this->entityManager->flush();

        $channel = 'backup:company_' . $message->companyId;

        try {
            $conn = $this->entityManager->getConnection();

            $zipContent = $this->backupService->generate(
                conn: $conn,
                companyId: $message->companyId,
                companyName: $company->getName() ?? '',
                companyCui: (string) ($company->getCif() ?? ''),
                includeFiles: $message->includeFiles,
                progressCallback: function (int $percent, string $step) use ($job, $channel) {
                    $job->setProgress($percent);
                    $job->setCurrentStep($step);
                    $this->entityManager->flush();

                    $this->centrifugo->publish($channel, [
                        'event' => 'backup_progress',
                        'jobId' => (string) $job->getId(),
                        'progress' => $percent,
                        'step' => $step,
                    ]);
                },
            );

            $storagePath = sprintf('backups/%s/%s.zip', $message->companyId, $job->getId());
            $storage = $this->storageResolver->resolveForCompany($company);
            $storage->write($storagePath, $zipContent);

            $companyName = $this->sanitizeFilename($company->getName() ?? 'company');
            $filename = sprintf('backup-%s-%s.zip', $companyName, date('Y-m-d'));

            $job->setStatus('completed');
            $job->setProgress(100);
            $job->setCurrentStep(null);
            $job->setStoragePath($storagePath);
            $job->setFilename($filename);
            $job->setFileSize(strlen($zipContent));
            $job->setCompletedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            $this->centrifugo->publish($channel, [
                'event' => 'backup_completed',
                'jobId' => (string) $job->getId(),
                'filename' => $filename,
                'fileSize' => strlen($zipContent),
            ]);

            if ($user) {
                $downloadUrl = sprintf('/v1/backup/%s/download', $job->getId());
                $notification = $this->notificationService->createNotification(
                    $user,
                    'backup_ready',
                    'Backup disponibil',
                    sprintf('Backup-ul companiei %s este gata de descarcare.', $company->getName()),
                    [
                        'downloadUrl' => $downloadUrl,
                        'filename' => $filename,
                        'fileSize' => strlen($zipContent),
                    ],
                );
                $notification->setLink($downloadUrl);
                $this->entityManager->flush();
            }

            $this->logger->info('Backup generated', [
                'jobId' => (string) $job->getId(),
                'companyId' => $message->companyId,
                'path' => $storagePath,
                'size' => strlen($zipContent),
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

            $this->logger->error('Backup generation failed', [
                'jobId' => (string) $job->getId(),
                'companyId' => $message->companyId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sanitizeFilename(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);
    }
}
