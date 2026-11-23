<?php

namespace App\Command\Spv;

use App\Command\Support\CommandProgress;
use App\Entity\Company;
use App\Entity\SpvDocument;
use App\Repository\CompanyRepository;
use App\Repository\SpvDocumentRepository;
use App\Service\Spv\SpvDocumentIngestionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Retention for archived SPV PDFs. The file is deleted after the company's
 * retention period (Company.archiveRetentionYears, default 5 years, null =
 * keep forever); the SpvDocument row stays with purgedAt set so the inbox
 * history remains complete.
 */
#[AsCommand(
    name: 'app:spv:cleanup',
    description: 'Delete archived SPV PDFs older than each company\'s retention period (rows are kept, marked purged)',
)]
class CleanupSpvDocumentsCommand extends Command
{
    public const DEFAULT_RETENTION_YEARS = 5;
    private const BATCH_SIZE = 200;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SpvDocumentRepository $repository,
        private readonly SpvDocumentIngestionService $ingestion,
        private readonly LoggerInterface $logger,
        private readonly CompanyRepository $companyRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be deleted without touching storage');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $purged = 0;

        // Companies that keep their PDFs for a limited time and still hold a PDF that is not purged,
        // in id-ordered batches so memory stays flat.
        $candidates = static fn ($qb) => $qb
            ->andWhere('c.archiveRetentionYears IS NULL OR c.archiveRetentionYears > 0')
            ->andWhere('EXISTS (SELECT 1 FROM ' . SpvDocument::class . ' sd WHERE sd.company = c AND sd.pdfPath IS NOT NULL AND sd.purgedAt IS NULL)');
        $total = (int) $candidates($this->companyRepository->createQueryBuilder('c')->select('COUNT(c.id)'))->getQuery()->getSingleScalarResult();
        $progress = new CommandProgress($output, 'spv:cleanup', $total);
        $progress->start(sprintf('%d companies with SPV PDFs to check%s', $total, $dryRun ? ' (dry-run)' : ''));

        $afterId = null;
        do {
            $companies = $candidates($this->companyRepository->createBatchQueryBuilder($afterId, self::BATCH_SIZE))->getQuery()->getResult();
            if ($companies === []) {
                break;
            }
            $afterId = end($companies)->getId();
            $companyIds = array_map(static fn (Company $c) => $c->getId(), $companies);
            $this->entityManager->clear();

            foreach ($companyIds as $companyId) {
                $progress->advance();
                $n = $this->purgeCompany($io, $companyId, $dryRun);
                $purged += $n;
                $progress->count('purged', $n);
            }
        } while (count($companies) === self::BATCH_SIZE);
        $progress->finish();

        $io->success(sprintf('%s%d SPV PDF(s) purged', $dryRun ? '[dry-run] ' : '', $purged));

        return Command::SUCCESS;
    }

    private function purgeCompany(SymfonyStyle $io, $companyId, bool $dryRun): int
    {
        $purged = 0;
        $company = $this->entityManager->find(Company::class, $companyId);
        if ($company === null) {
            return 0;
        }
        $years = $company->getArchiveRetentionYears() ?? self::DEFAULT_RETENTION_YEARS;
        if ($years <= 0) {
            return 0; // explicit "keep forever"
        }
        $cutoff = new \DateTimeImmutable("-{$years} years");

        do {
            $before = $purged;
            $batch = $this->repository->findExpiredPdfs($company, $cutoff, self::BATCH_SIZE);
            foreach ($batch as $doc) {
                $path = $doc->getPdfPath();
                if ($dryRun) {
                    $io->text(sprintf('[dry-run] %s %s (%s)', $company->getCif(), $doc->getMessageType(), $path));
                    $purged++;
                    continue;
                }
                try {
                    $storage = $this->ingestion->storageFor($doc);
                    if ($path && $storage->fileExists($path)) {
                        $storage->delete($path);
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('SPV cleanup: could not delete file', ['path' => $path, 'error' => $e->getMessage()]);
                    continue;
                }
                $doc->setPdfPath(null);
                $doc->setPurgedAt(new \DateTimeImmutable());
                $purged++;
            }
            if (!$dryRun) {
                $this->entityManager->flush();
                $this->entityManager->clear();
                $company = $this->entityManager->find(Company::class, $companyId);
            }
        } while (!$dryRun && count($batch) === self::BATCH_SIZE && $company !== null && $purged > $before);
        $this->entityManager->clear();

        return $purged;
    }
}
