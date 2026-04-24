<?php

namespace App\Command;

use App\Service\EuVatRateService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Refresh EU VAT rates from the ibericode/vat-rates GitHub repo.
 * Scheduled monthly. If the fetch fails, the existing cached rates are kept.
 */
#[AsCommand(
    name: 'app:vat-rates:sync',
    description: 'Refresh EU VAT rates from GitHub (monthly). Keeps existing rates on failure.',
)]
class SyncEuVatRatesCommand extends Command
{
    public function __construct(
        private readonly EuVatRateService $euVatRateService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->euVatRateService->refresh()) {
            $io->success('EU VAT rates refreshed from GitHub.');
            return Command::SUCCESS;
        }

        $io->warning('Refresh failed; existing cached rates remain active.');
        return Command::FAILURE;
    }
}
