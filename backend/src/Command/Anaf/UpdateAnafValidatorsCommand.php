<?php

declare(strict_types=1);

namespace App\Command\Anaf;

use App\Service\Declaration\DukIntegratorService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Refreshes ANAF's DUKIntegrator validator jars from ANAF's manifest and restarts
 * the Java validation service when something changed. Runs weekly from the
 * scheduler; run it by hand after ANAF publishes a new form version.
 */
#[AsCommand(name: 'app:anaf:update-validators', description: 'Download the current ANAF declaration validators (DUKIntegrator jars) and restart the Java service if they changed')]
final class UpdateAnafValidatorsCommand extends Command
{
    public function __construct(
        private readonly DukIntegratorService $duk,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('types', InputArgument::IS_ARRAY, 'Form codes to refresh (default: the built-in set)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Every form listed in versiuni.xml (~170)')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Only report what is outdated')
            ->addOption('no-restart', null, InputOption::VALUE_NONE, 'Do not restart the Java service after an update');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $script = $this->projectDir . '/tools/duk-integrator/update-jars.sh';
        if (!is_file($script)) {
            $output->writeln(sprintf('<error>%s not found</error>', $script));

            return Command::FAILURE;
        }

        $args = ['sh', $script];
        if ($input->getOption('all')) {
            $args[] = '--all';
        }
        if ($input->getOption('check')) {
            $args[] = '--check';
        }
        foreach ($input->getArgument('types') as $type) {
            $args[] = strtoupper((string) $type);
        }

        $process = new Process($args, $this->projectDir, timeout: 1800);
        $process->run(function (string $stream, string $line) use ($output): void {
            $output->write($line);
        });
        $code = $process->getExitCode();

        if ($code === 2 || $code === 1) {
            $this->logger->error('ANAF validator update failed', ['exit' => $code]);

            return Command::FAILURE;
        }

        if ($code === 3 && !$input->getOption('check') && !$input->getOption('no-restart')) {
            $output->writeln('Validators changed, restarting the Java service…');
            $restart = new Process(['supervisorctl', 'restart', 'java-services'], timeout: 120);
            $restart->run();
            if (!$restart->isSuccessful()) {
                $output->writeln('<comment>supervisorctl not available; restart the Java service manually.</comment>');
            } else {
                for ($i = 0; $i < 30; $i++) {
                    sleep(2);
                    if ($this->duk->isAvailable()) {
                        $output->writeln('Java service is back with DUKIntegrator loaded.');
                        break;
                    }
                }
            }
            $this->logger->info('ANAF validators updated');
        }

        return $code === 3 && $input->getOption('check') ? 3 : Command::SUCCESS;
    }
}
