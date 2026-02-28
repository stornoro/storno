<?php

namespace App\MessageHandler;

use App\Message\ProcessImportMessage;
use App\Repository\ImportJobRepository;
use App\Service\Import\ImportOrchestrator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ProcessImportHandler
{
    public function __construct(
        private readonly ImportJobRepository $importJobRepository,
        private readonly ImportOrchestrator $importOrchestrator,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ProcessImportMessage $message): void
    {
        $job = $this->importJobRepository->find($message->importJobId);
        if (!$job) {
            $this->logger->warning('ImportJob not found for processing', ['id' => $message->importJobId]);
            return;
        }

        if ($job->getStatus() !== 'processing') {
            $this->logger->warning('ImportJob not in processing status', [
                'id' => $message->importJobId,
                'status' => $job->getStatus(),
            ]);
            return;
        }

        try {
            $this->importOrchestrator->executeImport($job);
        } catch (\Throwable $e) {
            $this->logger->error('Import processing failed', [
                'id' => $message->importJobId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
