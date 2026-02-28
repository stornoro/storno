<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use App\Services\Misc\GitVersionInfo;
// use Shivas\VersioningBundle\Service\VersionManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:version', 'Shows the currently installed version of Storno.ro.')]
class VersionCommand extends Command
{
    public function __construct(protected GitVersionInfo $gitVersionInfo)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // $message = 'E-Factura version: ' . $this->versionManager->getVersion()->toString();
        $message = '';
        if ($this->gitVersionInfo->getGitBranchName() !== null) {
            $message .= ' Git branch: ' . $this->gitVersionInfo->getGitBranchName();
            $message .= ', Git commit: ' . $this->gitVersionInfo->getGitCommitHash();
        }

        $io->success($message);

        $io->info('PHP version: ' . PHP_VERSION);
        $io->info('Symfony version: ' . $this->getApplication()->getVersion());
        $io->info('OS: ' . php_uname());
        $io->info('PHP extension: ' . implode(', ', get_loaded_extensions()));

        return Command::SUCCESS;
    }
}
