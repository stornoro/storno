<?php

namespace App\Command\Partner;

use App\Repository\CompanyRepository;
use App\Service\Partner\PartnerVerificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

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
    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly PartnerVerificationService $verificationService,
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

        $totals = ['checked' => 0, 'changed' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($this->companyRepository->findAll() as $company) {
            if ($only && (string) $company->getId() !== $only) {
                continue;
            }
            if ($company->getDeletedAt() !== null || !$company->getOrganization()?->isActive()) {
                continue;
            }
            $counts = $this->verificationService->verifyAll($company, $since);
            foreach ($counts as $key => $value) {
                $totals[$key] += $value;
            }
            if ($counts['checked'] + $counts['failed'] > 0) {
                $io->text(sprintf('%s: checked %d, changed %d, failed %d', $company->getName(), $counts['checked'], $counts['changed'], $counts['failed']));
            }
        }

        $io->success(sprintf('Partners checked: %d, changed: %d, failed: %d, skipped: %d.', $totals['checked'], $totals['changed'], $totals['failed'], $totals['skipped']));

        return Command::SUCCESS;
    }
}
