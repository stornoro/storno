<?php

namespace App\MessageHandler;

use App\Message\GenerateZipExportMessage;
use App\Repository\InvoiceRepository;
use App\Repository\UserRepository;
use App\Service\Export\ZipExportService;
use App\Service\NotificationService;
use App\Service\Storage\OrganizationStorageResolver;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
class GenerateZipExportHandler
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly UserRepository $userRepository,
        private readonly ZipExportService $zipExportService,
        private readonly FilesystemOperator $defaultStorage,
        private readonly OrganizationStorageResolver $storageResolver,
        private readonly NotificationService $notificationService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(GenerateZipExportMessage $message): void
    {
        $user = $this->userRepository->find($message->userId);
        if (!$user) {
            $this->logger->warning('User not found for ZIP export', ['userId' => $message->userId]);
            return;
        }

        $invoices = $this->invoiceRepository->findByIds($message->invoiceIds);

        // Filter to only invoices belonging to the requested company
        $companyId = $message->companyId;
        $invoices = array_filter($invoices, function ($invoice) use ($companyId) {
            $invoiceCompanyId = $invoice->getCompany()?->getId();
            return $invoiceCompanyId && (string) $invoiceCompanyId === $companyId;
        });

        if (empty($invoices)) {
            $this->logger->warning('No matching invoices for ZIP export', [
                'companyId' => $companyId,
                'invoiceIds' => $message->invoiceIds,
            ]);
            return;
        }

        try {
            $zipContent = $this->zipExportService->generate(array_values($invoices));

            $firstInvoice = reset($invoices);
            $storage = $firstInvoice->getCompany()
                ? $this->storageResolver->resolveForCompany($firstInvoice->getCompany())
                : $this->defaultStorage;

            $exportUuid = Uuid::v7();
            $storagePath = sprintf('exports/%s.zip', $exportUuid);
            $storage->write($storagePath, $zipContent);

            $filename = sprintf('facturi-%s.zip', date('Y-m-d'));
            $downloadUrl = sprintf('/v1/exports/%s.zip', $exportUuid);

            $notification = $this->notificationService->createNotification(
                $user,
                'export_ready',
                'Export ZIP disponibil',
                sprintf('Exportul cu %d facturi este gata.', count($invoices)),
                [
                    'downloadUrl' => $downloadUrl,
                    'filename' => $filename,
                    'storagePath' => $storagePath,
                    'companyId' => $companyId,
                ],
            );
            $notification->setLink($downloadUrl);
            $this->entityManager->flush();

            $this->logger->info('ZIP export generated', [
                'path' => $storagePath,
                'invoiceCount' => count($invoices),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('ZIP export generation failed', [
                'companyId' => $companyId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
