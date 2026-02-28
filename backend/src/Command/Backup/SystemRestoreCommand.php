<?php

namespace App\Command\Backup;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:backup:restore-system',
    description: 'Restore a full system backup from a ZIP archive created by app:backup:system',
)]
class SystemRestoreCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $projectDir,
        private readonly string $storageDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('backup-file', InputArgument::REQUIRED, 'Path to the system backup ZIP file')
            ->addOption('no-files', null, InputOption::VALUE_NONE, 'Skip restoring files')
            ->addOption('no-db', null, InputOption::VALUE_NONE, 'Skip restoring database')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview the backup contents without restoring')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $backupFile = $input->getArgument('backup-file');
        $dryRun = $input->getOption('dry-run');
        $force = $input->getOption('force');
        $skipFiles = $input->getOption('no-files');
        $skipDb = $input->getOption('no-db');

        $io->title('System Restore');

        if (!file_exists($backupFile)) {
            $io->error(sprintf('Backup file not found: %s', $backupFile));
            return Command::FAILURE;
        }

        $zip = new \ZipArchive();
        if ($zip->open($backupFile) !== true) {
            $io->error('Failed to open backup ZIP file.');
            return Command::FAILURE;
        }

        // Read manifest
        $manifestJson = $zip->getFromName('manifest.json');
        if ($manifestJson === false) {
            $io->error('Invalid backup: manifest.json not found in archive.');
            $zip->close();
            return Command::FAILURE;
        }

        $manifest = json_decode($manifestJson, true);
        if (!$manifest || ($manifest['type'] ?? '') !== 'system') {
            $io->error('Invalid backup: this is not a system backup (wrong type in manifest).');
            $zip->close();
            return Command::FAILURE;
        }

        // Display backup info
        $io->section('Backup Information');
        $io->definitionList(
            ['Created' => $manifest['createdAt'] ?? 'unknown'],
            ['Generator' => $manifest['generator'] ?? 'unknown'],
            ['Database tables' => $manifest['database']['tables'] ?? 0],
            ['Total rows' => number_format($manifest['database']['totalRows'] ?? 0)],
            ['Includes files' => ($manifest['includesFiles'] ?? false) ? 'Yes' : 'No'],
            ['Includes DB dump' => ($manifest['includesDbDump'] ?? false) ? 'Yes' : 'No'],
        );

        if (!empty($manifest['database']['tableCounts'])) {
            $io->section('Table row counts');
            $tableCounts = $manifest['database']['tableCounts'];
            ksort($tableCounts);
            $io->table(
                ['Table', 'Rows'],
                array_map(fn ($t, $c) => [$t, number_format($c)], array_keys($tableCounts), array_values($tableCounts)),
            );
        }

        // Check what's available in the archive
        $hasDbDump = $zip->locateName('database.sql') !== false;
        $hasJsonData = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_starts_with($name, 'data/') && str_ends_with($name, '.json')) {
                $hasJsonData = true;
                break;
            }
        }

        if (!$skipDb) {
            $io->section('Database restore method');
            if ($hasDbDump) {
                $io->text('Native SQL dump found — will use native restore (fastest).');
            } elseif ($hasJsonData) {
                $io->text('JSON data exports found — will restore via DBAL inserts.');
            } else {
                $io->warning('No database data found in backup.');
                $skipDb = true;
            }
        }

        if ($dryRun) {
            $zip->close();
            $io->note('Dry-run mode — nothing was restored.');
            return Command::SUCCESS;
        }

        // Confirmation
        if (!$force) {
            $io->caution('WARNING: This will REPLACE your current database and files with the backup contents.');
            $confirmed = $io->confirm('Are you sure you want to proceed? This cannot be undone.', false);
            if (!$confirmed) {
                $zip->close();
                $io->warning('Aborted.');
                return Command::SUCCESS;
            }
        }

        try {
            // ── Restore database ─────────────────────────────────────
            if (!$skipDb) {
                if ($hasDbDump) {
                    $io->text('Restoring database from SQL dump...');
                    $this->restoreFromSqlDump($zip, $io);
                } elseif ($hasJsonData) {
                    $io->text('Restoring database from JSON exports...');
                    $this->restoreFromJson($zip, $manifest, $io);
                }
                $io->text('  Database restore complete.');
            }

            // ── Restore files ────────────────────────────────────────
            if (!$skipFiles && ($manifest['includesFiles'] ?? false)) {
                $io->text('Restoring files...');
                $restoredFiles = $this->restoreFiles($zip, $io);
                $io->text(sprintf('  Restored %d files.', $restoredFiles));
            }

            $zip->close();
            $io->newLine();
            $io->success('System restore completed successfully.');

            $io->note([
                'Post-restore checklist:',
                '  1. Clear the Symfony cache: php bin/console cache:clear',
                '  2. Run migrations if needed: php bin/console doctrine:migrations:migrate',
                '  3. Verify the application is working correctly',
            ]);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $zip->close();
            $io->error(sprintf('Restore failed: %s', $e->getMessage()));
            return Command::FAILURE;
        }
    }

    private function restoreFromSqlDump(\ZipArchive $zip, SymfonyStyle $io): void
    {
        $sqlContent = $zip->getFromName('database.sql');
        if ($sqlContent === false) {
            throw new \RuntimeException('Failed to read database.sql from archive.');
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'db_restore_') . '.sql';
        file_put_contents($tmpFile, $sqlContent);

        try {
            $dbInfo = $this->getDatabaseInfo();

            if ($dbInfo['isMysql']) {
                $cmd = sprintf(
                    'mysql --host=%s --port=%s --user=%s %s %s < %s 2>&1',
                    escapeshellarg($dbInfo['host']),
                    escapeshellarg((string) $dbInfo['port']),
                    escapeshellarg($dbInfo['user']),
                    $dbInfo['password'] ? sprintf('--password=%s', escapeshellarg($dbInfo['password'])) : '',
                    escapeshellarg($dbInfo['database']),
                    escapeshellarg($tmpFile),
                );
            } elseif ($dbInfo['isPgsql']) {
                $env = sprintf('PGPASSWORD=%s ', escapeshellarg($dbInfo['password']));
                $cmd = sprintf(
                    '%spsql --host=%s --port=%s --username=%s %s < %s 2>&1',
                    $env,
                    escapeshellarg($dbInfo['host']),
                    escapeshellarg((string) $dbInfo['port']),
                    escapeshellarg($dbInfo['user']),
                    escapeshellarg($dbInfo['database']),
                    escapeshellarg($tmpFile),
                );
            } else {
                throw new \RuntimeException('Unsupported database driver for native SQL restore. Use JSON restore instead.');
            }

            exec($cmd, $output, $exitCode);

            if ($exitCode !== 0) {
                throw new \RuntimeException(sprintf(
                    'Database restore failed (exit code %d): %s',
                    $exitCode,
                    implode("\n", $output),
                ));
            }
        } finally {
            @unlink($tmpFile);
        }
    }

    private function restoreFromJson(\ZipArchive $zip, array $manifest, SymfonyStyle $io): void
    {
        $tableCounts = $manifest['database']['tableCounts'] ?? [];

        // Disable FK checks during restore
        $dbInfo = $this->getDatabaseInfo();
        if ($dbInfo['isMysql']) {
            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        } elseif ($dbInfo['isPgsql']) {
            $this->connection->executeStatement('SET session_replication_role = replica');
        }

        try {
            foreach ($tableCounts as $table => $expectedCount) {
                $jsonContent = $zip->getFromName("data/{$table}.json");
                if ($jsonContent === false) {
                    $io->text(sprintf('  Skipping %s (no JSON file in archive)', $table));
                    continue;
                }

                $rows = json_decode($jsonContent, true);
                if (!is_array($rows) || empty($rows)) {
                    continue;
                }

                // Truncate the table first
                if ($dbInfo['isMysql']) {
                    $this->connection->executeStatement("TRUNCATE TABLE `{$table}`");
                } else {
                    $this->connection->executeStatement("TRUNCATE TABLE \"{$table}\" CASCADE");
                }

                // Insert rows in batches
                $batchSize = 100;
                $inserted = 0;
                foreach (array_chunk($rows, $batchSize) as $batch) {
                    foreach ($batch as $row) {
                        $columns = array_keys($row);
                        $placeholders = array_fill(0, count($columns), '?');
                        $quotedColumns = array_map(fn ($c) => "`{$c}`", $columns);

                        $this->connection->executeStatement(
                            sprintf(
                                'INSERT INTO `%s` (%s) VALUES (%s)',
                                $table,
                                implode(', ', $quotedColumns),
                                implode(', ', $placeholders),
                            ),
                            array_values($row),
                        );
                        $inserted++;
                    }
                }

                $io->text(sprintf('  %s: %d rows', $table, $inserted));
            }
        } finally {
            // Re-enable FK checks
            if ($dbInfo['isMysql']) {
                $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            } elseif ($dbInfo['isPgsql']) {
                $this->connection->executeStatement('SET session_replication_role = DEFAULT');
            }
        }
    }

    private function restoreFiles(\ZipArchive $zip, SymfonyStyle $io): int
    {
        $count = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if (!str_starts_with($name, 'files/') || str_ends_with($name, '/')) {
                continue;
            }

            $relativePath = substr($name, 6); // Remove 'files/' prefix
            $targetPath = $this->storageDir . '/' . $relativePath;
            $targetDir = dirname($targetPath);

            if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
                $io->warning(sprintf('Cannot create directory: %s', $targetDir));
                continue;
            }

            $content = $zip->getFromIndex($i);
            if ($content !== false) {
                file_put_contents($targetPath, $content);
                $count++;
            }
        }

        return $count;
    }

    private function getDatabaseInfo(): array
    {
        $params = $this->connection->getParams();
        $driver = $params['driver'] ?? $params['driverClass'] ?? 'unknown';

        $isMysql = str_contains($driver, 'mysql') || str_contains($driver, 'pdo_mysql');
        $isPgsql = str_contains($driver, 'pgsql') || str_contains($driver, 'pdo_pgsql');

        return [
            'driver' => $driver,
            'isMysql' => $isMysql,
            'isPgsql' => $isPgsql,
            'host' => $params['host'] ?? '127.0.0.1',
            'port' => $params['port'] ?? ($isMysql ? 3306 : 5432),
            'database' => $params['dbname'] ?? $params['path'] ?? 'unknown',
            'user' => $params['user'] ?? '',
            'password' => $params['password'] ?? '',
        ];
    }
}
