<?php

namespace App\Command;

use App\Service\LicenseValidationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:post-update',
    description: 'Run after updating Storno.ro — migrations, cache clear, queue restart',
)]
class PostUpdateCommand extends Command
{
    public function __construct(
        private readonly LicenseValidationService $licenseValidationService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Storno.ro Post-Update');

        $warnings = [];

        // 1. Run migrations
        $io->section('Running database migrations...');
        try {
            $migrateCmd = $this->getApplication()->find('doctrine:migrations:migrate');
            $migrateInput = new ArrayInput(['--no-interaction' => true]);
            $migrateCmd->run($migrateInput, $output);
            $io->info('Migrations completed.');
        } catch (\Throwable $e) {
            $warnings[] = 'Migrations failed: ' . $e->getMessage();
            $io->warning('Migrations failed: ' . $e->getMessage());
        }

        // 2. Clear cache
        $io->section('Clearing cache...');
        try {
            $cacheClearCmd = $this->getApplication()->find('cache:clear');
            $cacheClearCmd->run(new ArrayInput([]), $output);
            $io->info('Cache cleared.');
        } catch (\Throwable $e) {
            $warnings[] = 'Cache clear failed: ' . $e->getMessage();
            $io->warning('Cache clear failed: ' . $e->getMessage());
        }

        // 3. Clear cache pools
        $io->section('Clearing cache pools...');
        try {
            $poolClearCmd = $this->getApplication()->find('cache:pool:clear');
            $poolClearInput = new ArrayInput(['pools' => ['cache.global_clearer']]);
            $poolClearCmd->run($poolClearInput, $output);
            $io->info('Cache pools cleared.');
        } catch (\Throwable $e) {
            $warnings[] = 'Cache pool clear failed: ' . $e->getMessage();
            $io->warning('Cache pool clear failed: ' . $e->getMessage());
        }

        // 4. Signal messenger workers to stop (they restart after current message)
        $io->section('Signaling messenger workers to restart...');
        try {
            $stopWorkersCmd = $this->getApplication()->find('messenger:stop-workers');
            $stopWorkersCmd->run(new ArrayInput([]), $output);
            $io->info('Workers signaled to restart.');
        } catch (\Throwable $e) {
            $warnings[] = 'Worker restart signal failed: ' . $e->getMessage();
            $io->warning('Worker restart signal failed: ' . $e->getMessage());
        }

        // 5. Version check
        $io->section('Version information');
        $updateInfo = $this->licenseValidationService->checkForUpdate();
        if ($updateInfo) {
            $io->info('Current version: ' . $updateInfo['currentVersion']);
            if ($updateInfo['updateAvailable']) {
                $io->note(sprintf(
                    'A newer version is available: %s (download: %s)',
                    $updateInfo['latestVersion'],
                    $updateInfo['downloadUrl'] ?? 'N/A',
                ));
            } else {
                $io->info('You are running the latest version.');
            }
        }

        // 6. Summary
        $io->newLine();
        if (empty($warnings)) {
            $io->success('Post-update completed successfully.');
        } else {
            $io->warning(sprintf('Post-update completed with %d warning(s):', count($warnings)));
            foreach ($warnings as $w) {
                $io->writeln('  ! ' . $w);
            }
        }

        return empty($warnings) ? Command::SUCCESS : Command::SUCCESS;
    }
}
