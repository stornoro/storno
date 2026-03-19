<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:update-client-countries', description: 'Update client countries from a CSV file')]
class UpdateClientCountriesCommand extends Command
{
    public function __construct(private readonly Connection $conn)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('csv', InputArgument::REQUIRED, 'Path to the client CSV file')
            ->addOption('company', null, InputOption::VALUE_REQUIRED, 'Company UUID')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be updated');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $csvPath = $input->getArgument('csv');
        $companyId = $input->getOption('company');
        $dryRun = $input->getOption('dry-run');

        if (!$companyId) {
            $output->writeln('<error>--company is required</error>');
            return Command::FAILURE;
        }

        $handle = fopen($csvPath, 'r');
        if (!$handle) {
            $output->writeln('<error>Cannot open CSV file</error>');
            return Command::FAILURE;
        }

        // Skip BOM
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $headers = fgetcsv($handle);
        $nameIdx = array_search('Denumire client', $headers);
        $countryIdx = array_search('Tara', $headers);
        $emailIdx = array_search('Email', $headers);

        if ($nameIdx === false || $countryIdx === false) {
            $output->writeln('<error>CSV must have "Denumire client" and "Tara" columns</error>');
            return Command::FAILURE;
        }

        $updated = 0;
        $skipped = 0;
        $batch = [];

        while (($row = fgetcsv($handle)) !== false) {
            $name = trim($row[$nameIdx] ?? '');
            $country = trim($row[$countryIdx] ?? '');
            $email = $emailIdx !== false ? trim($row[$emailIdx] ?? '') : '';

            if (!$name || !$country) {
                continue;
            }

            $batch[] = ['name' => $name, 'country' => $country, 'email' => $email];

            if (count($batch) >= 500) {
                $updated += $this->processBatch($batch, $companyId, $dryRun);
                $batch = [];
            }
        }

        if ($batch) {
            $updated += $this->processBatch($batch, $companyId, $dryRun);
        }

        fclose($handle);

        $output->writeln(sprintf('%s <info>%d</info> clients', $dryRun ? 'Would update' : 'Updated', $updated));

        return Command::SUCCESS;
    }

    private function processBatch(array $batch, string $companyId, bool $dryRun): int
    {
        $updated = 0;

        foreach ($batch as $row) {
            // Try email first, then name
            $where = 'company_id = ? AND deleted_at IS NULL';
            $params = [$companyId];

            if ($row['email']) {
                $where .= ' AND email = ?';
                $params[] = $row['email'];
            } else {
                $where .= ' AND name = ?';
                $params[] = $row['name'];
            }

            if (!$dryRun) {
                $affected = $this->conn->executeStatement(
                    "UPDATE client SET country = ? WHERE $where",
                    array_merge([$row['country']], $params),
                );
                $updated += $affected;
            } else {
                $updated++;
            }
        }

        return $updated;
    }
}
