<?php

declare(strict_types=1);

namespace App\Command\Anaf;

use App\Service\Anaf\AnafNomenclatorService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Mirror ANAF's nomenclators locally: counties + fiscal offices, every locality,
 * and the streets of localities already looked up (or of every locality with --strazi).
 */
#[AsCommand(name: 'app:anaf:nomenclator:sync', description: 'Refresh the local mirror of ANAF address and fiscal-office nomenclators')]
final class SyncAnafNomenclatorCommand extends Command
{
    public function __construct(private readonly AnafNomenclatorService $nomenclator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('strazi', null, InputOption::VALUE_NONE, 'Also fetch the streets of every locality (~13k requests, tens of minutes)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $n = $this->nomenclator->syncJudete();
        $output->writeln(sprintf('judete + organe fiscale: %d rows', $n));

        $judete = $this->nomenclator->judete();
        $total = 0;
        $keys = [];
        foreach ($judete as $j) {
            $count = $this->nomenclator->syncLocalitati((string) $j['code']);
            $total += $count;
            if ($input->getOption('strazi')) {
                foreach ($this->nomenclator->localitati((string) $j['code']) as $l) {
                    $keys[] = $j['code'] . '-' . $l['code'];
                }
            }
            usleep(150_000);
        }
        $output->writeln(sprintf('localitati: %d rows in %d judete', $total, count($judete)));

        if (!$input->getOption('strazi')) {
            $keys = $this->nomenclator->cachedStreetParents();
        }
        $streets = 0;
        foreach ($keys as $key) {
            [$judet, $localitate] = explode('-', $key, 2);
            try {
                $streets += $this->nomenclator->syncStrazi($judet, $localitate);
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<comment>strazi %s: %s</comment>', $key, $e->getMessage()));
            }
            usleep(120_000);
        }
        $output->writeln(sprintf('strazi: %d rows in %d localitati', $streets, count($keys)));

        return Command::SUCCESS;
    }
}
