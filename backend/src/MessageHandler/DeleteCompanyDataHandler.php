<?php

namespace App\MessageHandler;

use App\Message\DeleteCompanyDataMessage;
use App\Repository\CompanyRepository;
use App\Service\CompanyDataPurger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
class DeleteCompanyDataHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CompanyRepository $companyRepository,
        private readonly CompanyDataPurger $purger,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(DeleteCompanyDataMessage $message): void
    {
        // Disable soft-delete filter to access the soft-deleted company
        $filters = $this->em->getFilters();
        $filterWasEnabled = $filters->isEnabled('soft_delete');
        if ($filterWasEnabled) {
            $filters->disable('soft_delete');
        }

        try {
            $company = $this->companyRepository->find(Uuid::fromString($message->companyId));
            if (!$company) {
                $this->logger->warning('DeleteCompanyData: Company not found.', ['id' => $message->companyId]);
                return;
            }

            if (!$company->isDeleted()) {
                // Company was restored during grace period — skip hard delete
                $this->logger->info('DeleteCompanyData: Skipped — company was restored.', ['companyId' => $message->companyId]);
                return;
            }

            $conn = $this->em->getConnection();

            $this->purger->purge($conn, $message->companyId);

            // Finally delete the company itself
            $conn->executeStatement('DELETE FROM company WHERE id = ?', [$message->companyId]);

            $this->logger->info('DeleteCompanyData: Deletion completed.', ['companyId' => $message->companyId]);
        } finally {
            if ($filterWasEnabled) {
                $filters->enable('soft_delete');
            }
        }
    }
}
