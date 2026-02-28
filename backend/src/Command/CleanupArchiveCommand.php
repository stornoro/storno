<?php

namespace App\Command;

use App\Repository\CompanyRepository;
use App\Repository\InvoiceRepository;
use App\Service\Anaf\InvoiceArchiver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:archive:cleanup',
    description: 'Delete archived invoice files past retention period',
)]
class CleanupArchiveCommand extends Command
{
    private const BATCH_SIZE = 50;

    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly InvoiceArchiver $invoiceArchiver,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview without deleting files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');

        if ($dryRun) {
            $io->note('Running in dry-run mode — no files will be deleted.');
        }

        $companies = $this->companyRepository->findBy(['archiveEnabled' => true]);
        $totalDeleted = 0;
        $totalRetained = 0;
        $totalErrors = 0;

        foreach ($companies as $company) {
            $retentionYears = $company->getArchiveRetentionYears();

            // null = never delete
            if ($retentionYears === null) {
                $io->text(sprintf('  %s: retention = never, skipping.', $company->getName()));
                continue;
            }

            $cutoffDate = new \DateTimeImmutable("-{$retentionYears} years");

            $io->section(sprintf('%s (CIF %s) — retention: %d years, cutoff: %s',
                $company->getName(),
                $company->getCif(),
                $retentionYears,
                $cutoffDate->format('Y-m-d'),
            ));

            $expiredInvoices = $this->invoiceRepository->findExpiredArchived($company, $cutoffDate);
            $activeCount = $this->invoiceRepository->countActiveArchived($company, $cutoffDate);
            $totalRetained += $activeCount;

            if (empty($expiredInvoices)) {
                $io->text('  No expired archived invoices.');
                continue;
            }

            $batchCount = 0;
            foreach ($expiredInvoices as $invoice) {
                if ($dryRun) {
                    $io->text(sprintf('  [DRY-RUN] Would delete: %s (issued %s)',
                        $invoice->getAnafMessageId(),
                        $invoice->getIssueDate()->format('Y-m-d'),
                    ));
                    $totalDeleted++;
                    continue;
                }

                try {
                    $this->invoiceArchiver->delete($invoice);
                    $totalDeleted++;
                    $batchCount++;

                    if ($batchCount % self::BATCH_SIZE === 0) {
                        $this->entityManager->flush();
                    }
                } catch (\Throwable $e) {
                    $totalErrors++;
                    $this->logger->error('Failed to delete archived invoice', [
                        'invoiceId' => (string) $invoice->getId(),
                        'error' => $e->getMessage(),
                    ]);
                    $io->warning(sprintf('  Error deleting %s: %s', $invoice->getAnafMessageId(), $e->getMessage()));
                }
            }

            if (!$dryRun) {
                $this->entityManager->flush();
            }
        }

        $io->newLine();
        $io->definitionList(
            ['Deleted' => $totalDeleted],
            ['Retained' => $totalRetained],
            ['Errors' => $totalErrors],
        );

        if ($dryRun) {
            $io->success('Dry-run complete. No files were deleted.');
        } else {
            $io->success('Archive cleanup complete.');
        }

        return Command::SUCCESS;
    }
}
