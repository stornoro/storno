<?php

namespace App\Command;

use App\Service\LicenseValidationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Syncs license status from the Storno.ro SaaS for self-hosted instances.
 * Should be run periodically via cron (e.g. every 6 hours).
 *
 * In SaaS mode (no LICENSE_KEY), this command is a no-op.
 */
#[AsCommand(
    name: 'app:license:sync',
    description: 'Validate license key against Storno.ro SaaS and sync plan',
)]
class LicenseSyncCommand extends Command
{
    public function __construct(
        private readonly LicenseValidationService $licenseValidationService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->licenseValidationService->isSelfHosted()) {
            $io->note('Not a self-hosted instance (LICENSE_KEY not set). Nothing to do.');
            return Command::SUCCESS;
        }

        $io->info('Validating license key...');

        $data = $this->licenseValidationService->validate();

        if (!$data) {
            $io->error('License validation failed — server unreachable and no cached data.');
            return Command::FAILURE;
        }

        if (!($data['valid'] ?? false)) {
            $io->error('License key is invalid or revoked: ' . ($data['error'] ?? 'Unknown error'));
            return Command::FAILURE;
        }

        $this->licenseValidationService->syncLicense();

        // Show violations from the server response
        $violations = $data['violations'] ?? [];
        if (!empty($violations)) {
            $io->warning('License violations detected:');
            foreach ($violations as $violation) {
                $io->writeln('  ! ' . $violation);
            }
        }

        $io->success(sprintf(
            'License valid — Plan: %s, Organization: %s',
            $data['plan'] ?? 'unknown',
            $data['organizationName'] ?? 'unknown',
        ));

        return Command::SUCCESS;
    }
}
