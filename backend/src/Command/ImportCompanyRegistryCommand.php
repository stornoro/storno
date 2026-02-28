<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import-company-registry',
    description: 'Import Romanian company registry CSV files into a SQLite FTS5 database',
)]
class ImportCompanyRegistryCommand extends Command
{
    private const BATCH_SIZE = 1000;

    public function __construct(
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'Path to the directory containing the CSV files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $csvDir = rtrim($input->getArgument('path'), '/');

        if (!is_dir($csvDir)) {
            $io->error("Directory not found: {$csvDir}");
            return Command::FAILURE;
        }

        // Find all CSV files
        $csvFiles = glob($csvDir . '/*.csv');
        if (empty($csvFiles)) {
            $io->error("No CSV files found in {$csvDir}");
            return Command::FAILURE;
        }

        $io->title('Importing Romanian Company Registry');
        $io->text(sprintf('Found %d CSV file(s) in %s', count($csvFiles), $csvDir));

        // Prepare output directory
        $dataDir = $this->projectDir . '/var/data';
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        $finalPath = $dataDir . '/company_registry.sqlite';
        $tempPath = $dataDir . '/company_registry_import.sqlite';

        // Remove temp file if leftover from previous run
        if (file_exists($tempPath)) {
            unlink($tempPath);
        }

        // Create SQLite database
        $pdo = new \PDO('sqlite:' . $tempPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = OFF');
        $pdo->exec('PRAGMA temp_store = MEMORY');

        // Create schema
        $pdo->exec('
            CREATE TABLE companies (
                id INTEGER PRIMARY KEY,
                denumire TEXT NOT NULL,
                denumire_norm TEXT NOT NULL,
                cui TEXT,
                cod_inmatriculare TEXT,
                adresa TEXT,
                localitate TEXT,
                judet TEXT,
                radiat INTEGER DEFAULT 0
            )
        ');

        $totalRows = 0;
        $skippedNoCui = 0;

        foreach ($csvFiles as $csvFile) {
            $basename = basename($csvFile);
            $isRadiat = str_contains($basename, 'radiate') && !str_contains($basename, 'neradiate');
            $hasSediu = str_contains($basename, 'cu_sediu');

            $io->section("Processing: {$basename}");
            $io->text(sprintf('  Radiat: %s | Has address: %s', $isRadiat ? 'yes' : 'no', $hasSediu ? 'yes' : 'no'));

            $file = new \SplFileObject($csvFile, 'r');
            $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY | \SplFileObject::DROP_NEW_LINE);
            $file->setCsvControl('^');

            // Read header
            $header = $file->current();
            if (!$header || !in_array('DENUMIRE', $header)) {
                $io->warning("  Skipping: invalid header in {$basename}");
                continue;
            }
            $colMap = array_flip($header);
            $file->next();

            $stmt = $pdo->prepare('
                INSERT INTO companies (denumire, denumire_norm, cui, cod_inmatriculare, adresa, localitate, judet, radiat)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');

            $batchCount = 0;
            $fileRows = 0;
            $pdo->beginTransaction();

            while (!$file->eof()) {
                $row = $file->current();
                $file->next();

                if (!$row || count($row) < 2) {
                    continue;
                }

                $denumire = trim($row[$colMap['DENUMIRE'] ?? 0] ?? '');
                $cui = trim($row[$colMap['CUI'] ?? 1] ?? '');
                $codInmatriculare = trim($row[$colMap['COD_INMATRICULARE'] ?? 2] ?? '');

                // Skip rows without CUI (useless for invoicing)
                if ($cui === '') {
                    $skippedNoCui++;
                    continue;
                }

                $adresa = '';
                $localitate = '';
                $judet = '';

                if ($hasSediu) {
                    $adresa = trim($row[$colMap['ADRESA_COMPLETA'] ?? 5] ?? '');
                    $localitate = trim($row[$colMap['ADR_LOCALITATE'] ?? 7] ?? '');
                    $judet = trim($row[$colMap['ADR_JUDET'] ?? 8] ?? '');
                }

                $stmt->execute([
                    $denumire,
                    $this->normalize($denumire),
                    $cui,
                    $codInmatriculare ?: null,
                    $adresa ?: null,
                    $localitate ?: null,
                    $judet ?: null,
                    $isRadiat ? 1 : 0,
                ]);

                $batchCount++;
                $fileRows++;

                if ($batchCount >= self::BATCH_SIZE) {
                    $pdo->commit();
                    $pdo->beginTransaction();
                    $batchCount = 0;
                }
            }

            if ($batchCount > 0) {
                $pdo->commit();
            }

            $totalRows += $fileRows;
            $io->text(sprintf('  Imported: %s rows', number_format($fileRows)));
        }

        // Create indexes
        $io->text('Creating indexes...');
        $pdo->exec('CREATE INDEX idx_companies_cui ON companies(cui)');

        // Build FTS5 index
        $io->text('Building FTS5 full-text index...');
        $pdo->exec('
            CREATE VIRTUAL TABLE companies_fts USING fts5(
                denumire_norm,
                cui,
                content=\'companies\',
                content_rowid=\'id\'
            )
        ');
        $pdo->exec('INSERT INTO companies_fts(companies_fts) VALUES(\'rebuild\')');

        // Optimize
        $io->text('Optimizing database...');
        $pdo->exec('INSERT INTO companies_fts(companies_fts) VALUES(\'optimize\')');
        $pdo->exec('PRAGMA optimize');
        $pdo = null; // Close connection

        // Atomic rename
        if (file_exists($finalPath)) {
            unlink($finalPath);
            // Also remove WAL/SHM files if present
            @unlink($finalPath . '-wal');
            @unlink($finalPath . '-shm');
        }
        rename($tempPath, $finalPath);
        // Clean up temp WAL/SHM
        @unlink($tempPath . '-wal');
        @unlink($tempPath . '-shm');

        $io->newLine();
        $io->definitionList(
            ['Total imported' => number_format($totalRows)],
            ['Skipped (no CUI)' => number_format($skippedNoCui)],
            ['Database' => $finalPath],
            ['Size' => $this->formatFileSize(filesize($finalPath))],
        );

        $io->success('Company registry imported successfully.');

        return Command::SUCCESS;
    }

    /**
     * Normalize company name: strip diacritics, uppercase, collapse whitespace.
     */
    private function normalize(string $text): string
    {
        // Romanian diacritics map
        $map = [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
            'Ă' => 'A', 'Â' => 'A', 'Î' => 'I', 'Ș' => 'S', 'Ş' => 'S', 'Ț' => 'T', 'Ţ' => 'T',
        ];

        $text = strtr($text, $map);
        $text = mb_strtoupper($text, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', trim($text));

        return $text;
    }

    private function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }
        return number_format($bytes / 1024, 0) . ' KB';
    }
}
