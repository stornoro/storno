<?php

namespace App\Command\Efactura;

use App\Command\Support\CommandProgress;
use App\Entity\Company;
use App\Message\Anaf\SyncCompanyMessage;
use App\Repository\AnafTokenRepository;
use App\Repository\CompanyRepository;
use App\Service\Anaf\EFacturaSyncService;
use App\Service\LicenseManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[AsCommand(
    name: 'app:efactura:sync',
    description: 'Sync invoices from ANAF e-Factura SPV for all enabled companies',
)]
class SyncEFacturaCommand extends Command
{
    private const BATCH_SIZE = 200;

    /**
     * A company dispatched by a scheduled --async run is not dispatched again for this long.
     * It stays below the 30 minute schedule so a healthy queue never skips a run, and it stops
     * a backed-up queue from collecting a second message for a company that is still waiting.
     */
    private const DISPATCH_MARKER_TTL = 25 * 60;

    public function __construct(
        private readonly EFacturaSyncService $syncService,
        private readonly CompanyRepository $companyRepository,
        private readonly AnafTokenRepository $anafTokenRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly LicenseManager $licenseManager,
        private readonly CacheInterface $cache,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company', null, InputOption::VALUE_REQUIRED, 'Sync only a specific company (UUID)')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Override days back to sync')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Dispatch one queue message per company instead of syncing inline (the schedule uses this)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $companyUuid = $input->getOption('company');
        $daysOverride = $input->getOption('days') ? (int) $input->getOption('days') : null;
        $async = (bool) $input->getOption('async');

        if ($companyUuid) {
            // An explicit --company is an operator override: no plan, token or marker checks.
            $company = $this->companyRepository->find(Uuid::fromString($companyUuid));
            if (!$company) {
                $io->error('Company not found: ' . $companyUuid);
                return Command::FAILURE;
            }

            return $this->syncOne($io, $company, $daysOverride, $async);
        }

        // Scheduled run: honour the plan's auto-sync flag and sync interval, and skip companies
        // that cannot sync at all. Companies are read in id-ordered batches so memory stays flat.
        $total = (int) $this->companyRepository->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.syncEnabled = true')
            ->getQuery()
            ->getSingleScalarResult();
        if ($total === 0) {
            $io->info('No companies with sync enabled.');
            return Command::SUCCESS;
        }

        $progress = new CommandProgress($output, 'efactura:sync', $total);
        $progress->start(sprintf(
            '%s %d sync-enabled companies in batches of %d',
            $async ? 'dispatching a queue message for up to' : 'syncing inline up to',
            $total,
            self::BATCH_SIZE,
        ));

        $now = new \DateTimeImmutable();
        $totalNew = 0;
        $afterId = null;
        do {
            $batch = $this->companyRepository->createBatchQueryBuilder($afterId, self::BATCH_SIZE)
                ->addSelect('o')
                ->leftJoin('c.organization', 'o')
                ->andWhere('c.syncEnabled = true')
                ->getQuery()
                ->getResult();
            if ($batch === []) {
                break;
            }
            $afterId = end($batch)->getId();

            $due = [];
            $orgHasToken = [];
            foreach ($batch as $company) {
                $reason = $this->skipReason($company, $now, $orgHasToken);
                if ($reason !== null) {
                    $progress->count('skipped');
                    if ($output->isVerbose()) {
                        $io->text(sprintf('  Skipping %s (CIF: %s): %s', $company->getName(), $company->getCif(), $reason));
                    }
                    $progress->advance();
                    continue;
                }
                $due[] = (string) $company->getId();
            }
            $this->entityManager->clear();

            foreach ($due as $companyId) {
                if ($async) {
                    $this->dispatchOnce($companyId, $daysOverride) ? $progress->count('dispatched') : $progress->count('queued-already');
                } else {
                    $company = $this->companyRepository->find($companyId);
                    if ($company === null) {
                        $progress->advance();
                        continue;
                    }
                    $result = $this->syncService->syncCompany($company, $daysOverride);
                    $totalNew += $result->getNewInvoices();
                    $progress->count('synced');
                    $progress->count('new-invoices', $result->getNewInvoices());
                    if ($result->hasErrors()) {
                        $progress->count('with-errors');
                    }
                    $this->entityManager->clear();
                }
                $progress->advance();
            }
        } while (count($batch) === self::BATCH_SIZE);

        $progress->finish();
        $counters = $progress->counters();
        if ($async) {
            $io->success(sprintf('Dispatched %d sync jobs to queue (%d skipped, %d already queued).', $counters['dispatched'] ?? 0, $counters['skipped'] ?? 0, $counters['queued-already'] ?? 0));
        } else {
            $io->success(sprintf('Sync complete. New invoices: %d (%d companies, %d skipped, %d with errors).', $totalNew, $counters['synced'] ?? 0, $counters['skipped'] ?? 0, $counters['with-errors'] ?? 0));
        }

        // Always return SUCCESS: individual errors are already logged by the sync service.
        // Returning FAILURE causes the scheduler to log a noisy ERROR with will_retry:false,
        // which is misleading for transient ANAF issues (token expiry, rate limits, etc.).
        return Command::SUCCESS;
    }

    private function syncOne(SymfonyStyle $io, Company $company, ?int $daysOverride, bool $async): int
    {
        if ($async) {
            $this->messageBus->dispatch(new SyncCompanyMessage((string) $company->getId(), $daysOverride));
            $io->success(sprintf('Dispatched async sync: %s (CIF: %s)', $company->getName(), $company->getCif()));

            return Command::SUCCESS;
        }

        $io->section(sprintf('Syncing: %s (CIF: %s)', $company->getName(), $company->getCif()));
        $result = $this->syncService->syncCompany($company, $daysOverride);
        $io->text(sprintf(
            '  New: %d | Skipped: %d | Clients: %d | Products: %d',
            $result->getNewInvoices(),
            $result->getSkippedDuplicates(),
            $result->getNewClients(),
            $result->getNewProducts(),
        ));
        foreach ($result->getErrors() as $error) {
            $io->warning('  ' . $error);
        }

        return Command::SUCCESS;
    }

    /** @return bool false when a message for this company was already dispatched within the marker window */
    private function dispatchOnce(string $companyId, ?int $daysOverride): bool
    {
        $fresh = false;
        $this->cache->get('efactura_sync_dispatched.' . $companyId, function (ItemInterface $item) use (&$fresh) {
            $item->expiresAfter(self::DISPATCH_MARKER_TTL);
            $fresh = true;

            return time();
        });
        if (!$fresh) {
            return false;
        }

        $this->messageBus->dispatch(new SyncCompanyMessage($companyId, $daysOverride));

        return true;
    }

    /**
     * Why a company is not synced on this run, or null when it is due. A company is due when its
     * organization's plan allows auto-sync, the plan's interval has elapsed since the last
     * successful sync and SPV access was not denied. A company without a token is still
     * dispatched: the sync service reports the missing token to its users.
     *
     * @param array<string, bool> $orgHasToken per-batch cache of "organization has a shared token"
     */
    private function skipReason(Company $company, \DateTimeImmutable $now, array &$orgHasToken): ?string
    {
        $org = $company->getOrganization();
        if ($org) {
            if (!$this->licenseManager->canAutoSync($org)) {
                return sprintf('auto-sync not available on the %s plan', $this->licenseManager->getEffectivePlan($org));
            }

            $interval = $this->licenseManager->getSyncInterval($org);
            $lastSyncedAt = $company->getLastSyncedAt();
            if ($interval > 0 && $lastSyncedAt !== null) {
                $nextSyncAt = $lastSyncedAt->modify(sprintf('+%d seconds', $interval));
                if ($nextSyncAt > $now) {
                    return sprintf('next sync allowed at %s (%s plan)', $nextSyncAt->format('Y-m-d H:i:s'), $this->licenseManager->getEffectivePlan($org));
                }
            }
        }

        if ($company->getSpvAccessError() !== null) {
            return 'SPV access denied by ANAF, re-link a token or sync manually';
        }

        return null;
    }
}
