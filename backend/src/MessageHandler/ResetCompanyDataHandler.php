<?php

namespace App\MessageHandler;

use App\Manager\CompanyManager;
use App\Manager\DocumentSeriesManager;
use App\Message\ResetCompanyDataMessage;
use App\Repository\CompanyRepository;
use App\Service\CompanyDataPurger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
class ResetCompanyDataHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CompanyRepository $companyRepository,
        private readonly CompanyDataPurger $purger,
        private readonly CompanyManager $companyManager,
        private readonly DocumentSeriesManager $documentSeriesManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ResetCompanyDataMessage $message): void
    {
        $company = $this->companyRepository->find(Uuid::fromString($message->companyId));
        if (!$company) {
            $this->logger->warning('ResetCompanyData: Company not found.', ['id' => $message->companyId]);
            return;
        }

        $this->purger->purge($this->em->getConnection(), $message->companyId);

        // Reset sync timestamp so next sync re-fetches everything
        $company->setLastSyncedAt(null);
        $company->setSyncEnabled(false);

        // Recreate default data (same as company creation)
        $this->documentSeriesManager->ensureDefaultSeries($company);
        $this->companyManager->ensureDefaultEmailTemplates($company);
        $this->companyManager->ensureDefaultVatRates($company);

        $this->em->flush();

        $this->logger->info('ResetCompanyData: Reset completed.', ['companyId' => $message->companyId]);
    }
}
