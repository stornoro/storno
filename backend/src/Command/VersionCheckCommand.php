<?php

namespace App\Command;

use App\Service\LicenseValidationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:version:check',
    description: 'Check if a newer version of Storno.ro is available',
)]
class VersionCheckCommand extends Command
{
    public function __construct(
        private readonly LicenseValidationService $licenseValidationService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $updateInfo = $this->licenseValidationService->checkForUpdate();

        if (!$updateInfo) {
            $io->error('Could not check for updates — server unreachable.');
            return Command::FAILURE;
        }

        $io->info('Current version: ' . $updateInfo['currentVersion']);
        $io->info('Latest version:  ' . $updateInfo['latestVersion']);

        if ($updateInfo['updateAvailable']) {
            $io->note(sprintf(
                'Update available! Download: %s',
                $updateInfo['downloadUrl'] ?? 'https://github.com/stornoro/stornoro/releases/latest',
            ));
        } else {
            $io->success('You are running the latest version.');
        }

        return Command::SUCCESS;
    }
}
