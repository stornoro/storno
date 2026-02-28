<?php

namespace App\MessageHandler\Anaf;

use App\Message\Anaf\SyncCompanyMessage;
use App\Repository\CompanyRepository;
use App\Service\Anaf\EFacturaSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SyncCompanyHandler
{
    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly EFacturaSyncService $syncService,
        private readonly LoggerInterface $logger,
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

        $result = $this->syncService->syncCompany($company, $message->daysOverride);

        $this->logger->info('Async sync completed', [
            'company' => $company->getName(),
            'newInvoices' => $result->getNewInvoices(),
            'skipped' => $result->getSkippedDuplicates(),
            'errors' => count($result->getErrors()),
        ]);
    }
}
