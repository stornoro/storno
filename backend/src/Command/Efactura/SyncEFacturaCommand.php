<?php

namespace App\Command\Efactura;

use App\Message\Anaf\SyncCompanyMessage;
use App\Repository\CompanyRepository;
use App\Service\Anaf\EFacturaSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:efactura:sync',
    description: 'Sync invoices from ANAF e-Factura SPV for all enabled companies',
)]
class SyncEFacturaCommand extends Command
{
    public function __construct(
        private readonly EFacturaSyncService $syncService,
        private readonly CompanyRepository $companyRepository,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company', null, InputOption::VALUE_REQUIRED, 'Sync only a specific company (UUID)')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Override days back to sync')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Dispatch sync jobs to queue instead of processing synchronously');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $companyUuid = $input->getOption('company');
        $daysOverride = $input->getOption('days') ? (int) $input->getOption('days') : null;
        $async = $input->getOption('async');

        if ($companyUuid) {
            $company = $this->companyRepository->find(Uuid::fromString($companyUuid));
            if (!$company) {
                $io->error('Company not found: ' . $companyUuid);
                return Command::FAILURE;
            }
            $companies = [$company];
        } else {
            $companies = $this->companyRepository->findBy(['syncEnabled' => true]);
        }

        if (empty($companies)) {
            $io->info('No companies with sync enabled.');
            return Command::SUCCESS;
        }

        if ($async) {
            foreach ($companies as $company) {
                $this->messageBus->dispatch(new SyncCompanyMessage(
                    (string) $company->getId(),
                    $daysOverride,
                ));
                $io->text(sprintf('Dispatched async sync: %s (CIF: %s)', $company->getName(), $company->getCif()));
            }

            $io->success(sprintf('Dispatched %d sync jobs to queue.', count($companies)));
            return Command::SUCCESS;
        }

        // Synchronous mode (backwards compatible)
        $totalNew = 0;
        $totalErrors = 0;

        foreach ($companies as $company) {
            $io->section(sprintf('Syncing: %s (CIF: %s)', $company->getName(), $company->getCif()));

            $result = $this->syncService->syncCompany($company, $daysOverride);
            $totalNew += $result->getNewInvoices();
            $totalErrors += count($result->getErrors());

            $io->text(sprintf(
                '  New: %d | Skipped: %d | Clients: %d | Products: %d',
                $result->getNewInvoices(),
                $result->getSkippedDuplicates(),
                $result->getNewClients(),
                $result->getNewProducts(),
            ));

            if ($result->hasErrors()) {
                foreach ($result->getErrors() as $error) {
                    $io->warning('  ' . $error);
                }
            }
        }

        if ($totalErrors > 0) {
            $io->warning(sprintf('Sync complete with errors. New invoices: %d, Errors: %d', $totalNew, $totalErrors));
        } else {
            $io->success(sprintf('Sync complete. New invoices: %d', $totalNew));
        }

        // Always return SUCCESS — individual errors are already logged by the sync service.
        // Returning FAILURE causes the scheduler to log a noisy ERROR with will_retry:false,
        // which is misleading for transient ANAF issues (token expiry, rate limits, etc.).
        return Command::SUCCESS;
    }
}
