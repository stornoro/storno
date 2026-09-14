<?php

declare(strict_types=1);

namespace App\Command\Anaf;

use App\Service\Anaf\AnafFormVersionService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Daily: read ANAF's form manifest and record which forms changed version. */
#[AsCommand(name: 'app:anaf:form-versions', description: 'Record the current ANAF form versions (versiuni.xml) and report the ones that changed')]
final class WatchAnafFormVersionsCommand extends Command
{
    public function __construct(private readonly AnafFormVersionService $service, private readonly LoggerInterface $logger)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $changes = $this->service->refresh();
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        foreach ($changes as $form => $c) {
            $line = sprintf('%s: %s/%s -> %s/%s%s', $form, $c['from'][0], $c['from'][1], $c['to'][0], $c['to'][1], $c['storno'] ? '  (used by Storno)' : '');
            $output->writeln($line);
            if ($c['storno']) {
                $this->logger->notice('ANAF form version changed', ['form' => $form, 'from' => $c['from'], 'to' => $c['to']]);
            }
        }
        $overview = $this->service->overview();
        $output->writeln(sprintf('%d forms recorded, %d changed now, local validators outdated: %s', count($overview['forms']), count($changes), $overview['localOutdated'] ? implode(', ', $overview['localOutdated']) : 'none'));

        return Command::SUCCESS;
    }
}
