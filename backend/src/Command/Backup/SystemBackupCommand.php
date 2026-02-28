<?php

namespace App\Command\Backup;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:backup:system',
    description: 'Create a full system backup (database + files) as a ZIP archive',
)]
class SystemBackupCommand extends Command
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
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output path for the backup ZIP (default: var/backups/system-backup-{date}.zip)')
            ->addOption('no-files', null, InputOption::VALUE_NONE, 'Skip backing up uploaded files (database only)')
            ->addOption('no-db', null, InputOption::VALUE_NONE, 'Skip database dump (files only)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview what would be backed up without creating the archive');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $skipFiles = $input->getOption('no-files');
        $skipDb = $input->getOption('no-db');

        $io->title('System Backup');

        if ($skipFiles && $skipDb) {
            $io->error('Cannot skip both --no-files and --no-db.');
            return Command::FAILURE;
        }

        // Determine output path
        $outputPath = $input->getOption('output');
        if (!$outputPath) {
            $backupDir = $this->projectDir . '/var/backups';
            $outputPath = $backupDir . '/system-backup-' . date('Y-m-d_His') . '.zip';
        }

        // Gather stats
        $dbInfo = $this->getDatabaseInfo();
        $tableStats = $this->getTableStats();

        $io->section('Database');
        $io->text(sprintf('Driver: %s', $dbInfo['driver']));
        $io->text(sprintf('Database: %s', $dbInfo['database']));
        $io->text(sprintf('Host: %s', $dbInfo['host']));
        $io->newLine();

        $io->table(
            ['Table', 'Rows'],
            array_map(fn ($table, $count) => [$table, number_format($count)], array_keys($tableStats), array_values($tableStats)),
        );

        $totalRows = array_sum($tableStats);
        $io->text(sprintf('Total: %s rows across %d tables', number_format($totalRows), count($tableStats)));

        if (!$skipFiles) {
            $io->section('Files');
            $filesDir = $this->storageDir;
            if (is_dir($filesDir)) {
                $fileCount = 0;
                $totalSize = 0;
                $this->countFiles($filesDir, $fileCount, $totalSize);
                $io->text(sprintf('Storage directory: %s', $filesDir));
                $io->text(sprintf('Files: %s (%s)', number_format($fileCount), $this->formatBytes($totalSize)));
            } else {
                $io->text('Storage directory does not exist (no files to backup).');
            }
        }

        $io->section('Output');
        $io->text($outputPath);

        if ($dryRun) {
            $io->note('Dry-run mode — no backup was created.');
            return Command::SUCCESS;
        }

        // Create backup
        $io->section('Creating backup...');

        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true)) {
            $io->error(sprintf('Cannot create output directory: %s', $outputDir));
            return Command::FAILURE;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'sys_backup_') . '.zip';
        $zip = new \ZipArchive();

        if ($zip->open($tmpFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $io->error('Failed to create ZIP archive.');
            return Command::FAILURE;
        }

        try {
            // ── Database dump ────────────────────────────────────────
            if (!$skipDb) {
                $io->text('Dumping database...');
                $dumpFile = $this->dumpDatabase($dbInfo, $io);
                if ($dumpFile === null) {
                    $zip->close();
                    @unlink($tmpFile);
                    return Command::FAILURE;
                }
                $zip->addFile($dumpFile, 'database.sql');
                $io->text(sprintf('  Database dump: %s', $this->formatBytes(filesize($dumpFile))));
            }

            // ── Export all table data as JSON (portable fallback) ────
            if (!$skipDb) {
                $io->text('Exporting table data as JSON...');
                $exportedTables = 0;
                foreach ($tableStats as $table => $count) {
                    if ($count === 0) {
                        continue;
                    }
                    $rows = $this->connection->fetchAllAssociative("SELECT * FROM `{$table}`");
                    $zip->addFromString("data/{$table}.json", json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    $exportedTables++;
                }
                $io->text(sprintf('  Exported %d tables to JSON', $exportedTables));
            }

            // ── Files ────────────────────────────────────────────────
            if (!$skipFiles && is_dir($this->storageDir)) {
                $io->text('Archiving files...');
                $archivedFiles = $this->archiveFiles($zip, $this->storageDir);
                $io->text(sprintf('  Archived %d files', $archivedFiles));
            }

            // ── Manifest ─────────────────────────────────────────────
            $manifest = [
                'version' => '1.0',
                'type' => 'system',
                'generator' => 'app:backup:system',
                'createdAt' => (new \DateTimeImmutable())->format('c'),
                'database' => [
                    'driver' => $dbInfo['driver'],
                    'tables' => count($tableStats),
                    'totalRows' => $totalRows,
                    'tableCounts' => $tableStats,
                ],
                'includesFiles' => !$skipFiles,
                'includesDbDump' => !$skipDb,
            ];
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            $zip->close();

            // Clean up temp dump file
            if (!$skipDb && isset($dumpFile)) {
                @unlink($dumpFile);
            }

            // Move to final location
            if (!rename($tmpFile, $outputPath)) {
                copy($tmpFile, $outputPath);
                @unlink($tmpFile);
            }

            $finalSize = filesize($outputPath);
            $io->newLine();
            $io->success(sprintf(
                'System backup created: %s (%s)',
                $outputPath,
                $this->formatBytes($finalSize),
            ));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $zip->close();
            @unlink($tmpFile);
            if (isset($dumpFile)) {
                @unlink($dumpFile);
            }
            $io->error(sprintf('Backup failed: %s', $e->getMessage()));
            return Command::FAILURE;
        }
    }

    private function getDatabaseInfo(): array
    {
        $params = $this->connection->getParams();
        $driver = $params['driver'] ?? $params['driverClass'] ?? 'unknown';

        // Normalize driver name
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

    private function getTableStats(): array
    {
        $tables = [];
        $schemaManager = $this->connection->createSchemaManager();

        foreach ($schemaManager->listTableNames() as $table) {
            // Skip Doctrine migration tracking table
            if ($table === 'doctrine_migration_versions' || $table === 'messenger_messages') {
                continue;
            }
            $count = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM `{$table}`");
            $tables[$table] = $count;
        }

        ksort($tables);
        return $tables;
    }

    private function dumpDatabase(array $dbInfo, SymfonyStyle $io): ?string
    {
        $dumpFile = tempnam(sys_get_temp_dir(), 'db_dump_') . '.sql';

        if ($dbInfo['isMysql']) {
            $cmd = sprintf(
                'mysqldump --host=%s --port=%s --user=%s %s --single-transaction --routines --triggers --no-tablespaces %s > %s 2>&1',
                escapeshellarg($dbInfo['host']),
                escapeshellarg((string) $dbInfo['port']),
                escapeshellarg($dbInfo['user']),
                $dbInfo['password'] ? sprintf('--password=%s', escapeshellarg($dbInfo['password'])) : '',
                escapeshellarg($dbInfo['database']),
                escapeshellarg($dumpFile),
            );
        } elseif ($dbInfo['isPgsql']) {
            $env = sprintf('PGPASSWORD=%s ', escapeshellarg($dbInfo['password']));
            $cmd = sprintf(
                '%spg_dump --host=%s --port=%s --username=%s --no-owner --no-acl %s > %s 2>&1',
                $env,
                escapeshellarg($dbInfo['host']),
                escapeshellarg((string) $dbInfo['port']),
                escapeshellarg($dbInfo['user']),
                escapeshellarg($dbInfo['database']),
                escapeshellarg($dumpFile),
            );
        } else {
            $io->warning('Unsupported database driver for native dump. Using JSON export only.');
            @unlink($dumpFile);
            return null;
        }

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            $io->warning(sprintf(
                'Native database dump failed (exit code %d): %s. JSON export will still be included.',
                $exitCode,
                implode("\n", $output),
            ));
            @unlink($dumpFile);
            return null;
        }

        return $dumpFile;
    }

    private function archiveFiles(\ZipArchive $zip, string $dir): int
    {
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relativePath = str_replace($dir . '/', '', $file->getPathname());
            $zip->addFile($file->getPathname(), 'files/' . $relativePath);
            $count++;
        }

        return $count;
    }

    private function countFiles(string $dir, int &$fileCount, int &$totalSize): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $fileCount++;
                $totalSize += $file->getSize();
            }
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }
        return sprintf('%.1f %s', $size, $units[$i]);
    }
}
