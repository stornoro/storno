<?php

namespace App\Command;

use App\Command\Support\CommandProgress;
use App\Doctrine\Type\UuidType;
use App\Entity\Company;
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
    /** Invoices read, deleted and flushed per round. */
    private const BATCH_SIZE = 50;
    private const COMPANY_BATCH_SIZE = 200;

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

        // Companies with an archive and a finite retention (null = never delete), in id-ordered
        // batches so memory stays flat.
        $candidates = static fn ($qb) => $qb
            ->andWhere('c.archiveEnabled = true')
            ->andWhere('c.archiveRetentionYears IS NOT NULL');
        $total = (int) $candidates($this->companyRepository->createQueryBuilder('c')->select('COUNT(c.id)'))->getQuery()->getSingleScalarResult();
        $progress = new CommandProgress($output, 'archive:cleanup', $total);
        $progress->start(sprintf('%d companies with a finite archive retention%s', $total, $dryRun ? ' (dry-run)' : ''));

        $totalDeleted = 0;
        $totalRetained = 0;
        $totalErrors = 0;
        $afterId = null;
        do {
            $companies = $candidates($this->companyRepository->createBatchQueryBuilder($afterId, self::COMPANY_BATCH_SIZE))->getQuery()->getResult();
            if ($companies === []) {
                break;
            }
            $afterId = end($companies)->getId();
            $companyIds = array_map(static fn ($c) => $c->getId(), $companies);
            $this->entityManager->clear();

            foreach ($companyIds as $companyId) {
                $progress->advance();
                $company = $this->entityManager->find(Company::class, $companyId);
                if ($company === null) {
                    continue;
                }
                $retentionYears = $company->getArchiveRetentionYears();
                $cutoffDate = new \DateTimeImmutable("-{$retentionYears} years");
                $companyName = $company->getName();
                $companyCif = $company->getCif();

                $totalRetained += $this->invoiceRepository->countActiveArchived($company, $cutoffDate);

                $io->section(sprintf('%s (CIF %s) — retention: %d years, cutoff: %s', $companyName, $companyCif, $retentionYears, $cutoffDate->format('Y-m-d')));

                $deleted = 0;
                $lastInvoiceId = null;
                $found = false;
                do {
                    $qb = $this->invoiceRepository->createQueryBuilder('i')
                        ->where('i.company = :company')
                        ->andWhere('i.xmlPath IS NOT NULL')
                        ->andWhere('i.issueDate < :cutoff')
                        ->setParameter('company', $company)
                        ->setParameter('cutoff', $cutoffDate)
                        ->orderBy('i.id', 'ASC')
                        ->setMaxResults(self::BATCH_SIZE);
                    if ($lastInvoiceId !== null) {
                        $qb->andWhere('i.id > :after')->setParameter('after', $lastInvoiceId, UuidType::NAME);
                    }
                    $expiredInvoices = $qb->getQuery()->getResult();
                    if ($expiredInvoices === []) {
                        break;
                    }
                    $lastInvoiceId = end($expiredInvoices)->getId();

                    $found = true;

                    foreach ($expiredInvoices as $invoice) {
                        if ($dryRun) {
                            $io->text(sprintf('  [DRY-RUN] Would delete: %s (issued %s)',
                                $invoice->getAnafMessageId(),
                                $invoice->getIssueDate()->format('Y-m-d'),
                            ));
                            $totalDeleted++;
                            $deleted++;
                            continue;
                        }

                        try {
                            $this->invoiceArchiver->delete($invoice);
                            $totalDeleted++;
                            $deleted++;
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
                    $this->entityManager->clear();
                    $company = $this->entityManager->find(Company::class, $companyId);
                } while (count($expiredInvoices) === self::BATCH_SIZE && $company !== null);

                if (!$found) {
                    $io->text('  No expired archived invoices.');
                }
                $progress->count('deleted', $deleted);
                $this->entityManager->clear();
            }
        } while (count($companies) === self::COMPANY_BATCH_SIZE);
        $progress->finish();

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
