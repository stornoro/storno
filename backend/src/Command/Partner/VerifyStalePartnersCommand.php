<?php

namespace App\Command\Partner;

use App\Command\Support\CommandProgress;
use App\Doctrine\Type\UuidType;
use App\Entity\Client;
use App\Entity\Supplier;
use App\Repository\CompanyRepository;
use App\Service\Partner\PartnerVerificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Re-checks at ANAF / VIES every partner not verified in the last 30 days and
 * notifies the company when a partner became inactive, lost its VAT
 * registration or moved to VAT on collection.
 */
#[AsCommand(
    name: 'app:partners:verify-stale',
    description: 'Re-verify clients and suppliers at ANAF / VIES when their last check is older than 30 days',
)]
class VerifyStalePartnersCommand extends Command
{
    private const BATCH_SIZE = 200;

    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly PartnerVerificationService $verificationService,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company', null, InputOption::VALUE_REQUIRED, 'Limit to one company UUID')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Re-check partners checked more than this many days ago', (string) PartnerVerificationService::STALE_AFTER_DAYS);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = max(0, (int) $input->getOption('days'));
        $since = new \DateTimeImmutable(sprintf('-%d days', $days));
        $only = $input->getOption('company');

        // Only companies of active organizations that have at least one partner due for a check,
        // read in id-ordered batches so memory stays flat.
        $due = static function ($qb) use ($since, $only) {
            $qb->join('c.organization', 'o')
                ->andWhere('o.isActive = true')
                ->andWhere('EXISTS (SELECT 1 FROM ' . Client::class . ' cl WHERE cl.company = c AND cl.deletedAt IS NULL AND cl.type = \'company\''
                    . ' AND (cl.vatStatusCheckedAt IS NULL OR cl.vatStatusCheckedAt < :since))'
                    . ' OR EXISTS (SELECT 1 FROM ' . Supplier::class . ' su WHERE su.company = c AND su.deletedAt IS NULL'
                    . ' AND (su.vatStatusCheckedAt IS NULL OR su.vatStatusCheckedAt < :since))')
                ->setParameter('since', $since);
            if ($only) {
                $qb->andWhere('c.id = :only')->setParameter('only', Uuid::fromString($only), UuidType::NAME);
            }

            return $qb;
        };

        $total = (int) $due($this->companyRepository->createQueryBuilder('c')->select('COUNT(c.id)'))->getQuery()->getSingleScalarResult();
        $progress = new CommandProgress($output, 'partners:verify-stale', $total);
        $progress->start(sprintf('%d companies with partners not checked in the last %d days', $total, $days));

        $totals = ['checked' => 0, 'changed' => 0, 'failed' => 0, 'skipped' => 0];
        $afterId = null;
        do {
            $batch = $due($this->companyRepository->createBatchQueryBuilder($afterId, self::BATCH_SIZE))->getQuery()->getResult();
            if ($batch === []) {
                break;
            }
            $afterId = end($batch)->getId();

            foreach ($batch as $company) {
                $counts = $this->verificationService->verifyAll($company, $since);
                foreach ($counts as $key => $value) {
                    $totals[$key] += $value;
                    $progress->count($key, $value);
                }
                if ($counts['checked'] + $counts['failed'] > 0) {
                    $io->text(sprintf('%s: checked %d, changed %d, failed %d', $company->getName(), $counts['checked'], $counts['changed'], $counts['failed']));
                }
                $progress->advance();
            }
            $this->entityManager->clear();
        } while (count($batch) === self::BATCH_SIZE);
        $progress->finish();

        $io->success(sprintf('Partners checked: %d, changed: %d, failed: %d, skipped: %d.', $totals['checked'], $totals['changed'], $totals['failed'], $totals['skipped']));

        return Command::SUCCESS;
    }
}
