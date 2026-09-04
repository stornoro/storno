<?php

namespace App\Command\Spv;

use App\Entity\Company;
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

        /** @var Company[] $companies */
        $companies = $this->entityManager->getRepository(Company::class)->findAll();

        foreach ($companies as $company) {
            $years = $company->getArchiveRetentionYears() ?? self::DEFAULT_RETENTION_YEARS;
            if ($years <= 0) {
                continue; // explicit "keep forever"
            }
            $cutoff = new \DateTimeImmutable("-{$years} years");

            do {
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
                }
            } while (!$dryRun && count($batch) === self::BATCH_SIZE);
        }

        $io->success(sprintf('%s%d SPV PDF(s) purged', $dryRun ? '[dry-run] ' : '', $purged));

        return Command::SUCCESS;
    }
}
