<?php

namespace App\MessageHandler\Anaf;

use App\Message\Anaf\SyncCompanyMessage;
use App\Repository\CompanyRepository;
use App\Service\Anaf\EFacturaSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SyncCompanyHandler
{
    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly EFacturaSyncService $syncService,
        private readonly LoggerInterface $logger,
        private readonly LockFactory $lockFactory,
    ) {}

    public function __invoke(SyncCompanyMessage $message): void
    {
        $company = $this->companyRepository->find($message->companyId);
        if (!$company) {
            $this->logger->warning('Company not found for async sync', ['id' => $message->companyId]);
            return;
        }

        $this->logger->info('Starting async sync for company', [
            'company' => $company->getName(),
            'cif' => $company->getCif(),
        ]);

        // One sync per company at a time. A second message for a company already being synced
        // (a manual sync during the scheduled one, a redelivery) is dropped: the running sync
        // reads the same ANAF message list.
        $lock = $this->lockFactory->createLock('efactura-sync-' . $message->companyId, 900);
        if (!$lock->acquire()) {
            $this->logger->info('Sync already running for company, message dropped', ['id' => $message->companyId]);

            return;
        }

        try {
            $result = $this->syncService->syncCompany($company, $message->daysOverride);
        } finally {
            $lock->release();
        }

        $this->logger->info('Async sync completed', [
            'company' => $company->getName(),
            'newInvoices' => $result->getNewInvoices(),
            'skipped' => $result->getSkippedDuplicates(),
            'errors' => count($result->getErrors()),
        ]);
    }
}
