<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:download-company-registry',
    description: 'Download the prebuilt Romanian company registry SQLite database (used for client search)',
)]
class DownloadCompanyRegistryCommand extends Command
{
    private const DEFAULT_URL = 'https://get.storno.ro/data/company_registry.sqlite';

    public function __construct(
        private readonly string $projectDir,
        private readonly HttpClientInterface $httpClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('url', null, InputOption::VALUE_REQUIRED, 'Source URL for the SQLite file (overrides env COMPANY_REGISTRY_URL)');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Re-download even if the local file matches the remote checksum');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $url = $input->getOption('url')
            ?: ($_ENV['COMPANY_REGISTRY_URL'] ?? null)
            ?: self::DEFAULT_URL;
        $force = (bool) $input->getOption('force');

        $dataDir = $this->projectDir . '/var/data';
        if (!is_dir($dataDir) && !mkdir($dataDir, 0755, true) && !is_dir($dataDir)) {
            $io->error("Cannot create data directory: {$dataDir}");
            return Command::FAILURE;
        }

        $finalPath = $dataDir . '/company_registry.sqlite';
        $tmpPath = $finalPath . '.download';

        $io->section('Storno — Company registry download');
        $io->definitionList(
            ['Source' => $url],
            ['Target' => $finalPath],
        );

        $remoteChecksum = $this->fetchChecksum($url . '.sha256');

        if (!$force && $remoteChecksum && file_exists($finalPath)) {
            $localChecksum = hash_file('sha256', $finalPath);
            if ($localChecksum === $remoteChecksum) {
                $io->success('Local registry is already up to date — skipping download (use --force to re-download).');
                return Command::SUCCESS;
            }
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 600,
                'max_duration' => 1800,
            ]);
            if ($response->getStatusCode() !== 200) {
                $io->error(sprintf('Download failed: HTTP %d', $response->getStatusCode()));
                return Command::FAILURE;
            }

            $totalBytes = (int) ($response->getHeaders(false)['content-length'][0] ?? 0);
            $progress = $totalBytes > 0 ? $io->createProgressBar($totalBytes) : null;
            $progress?->setFormat(' %current%/%max% bytes [%bar%] %percent:3s%% — %elapsed%/%estimated%');

            $fp = fopen($tmpPath, 'wb');
            if ($fp === false) {
                $io->error("Cannot open temp file for writing: {$tmpPath}");
                return Command::FAILURE;
            }

            foreach ($this->httpClient->stream($response) as $chunk) {
                $bytes = $chunk->getContent();
                fwrite($fp, $bytes);
                $progress?->advance(strlen($bytes));
            }

            fclose($fp);
            $progress?->finish();
            $io->newLine(2);
        } catch (\Throwable $e) {
            @unlink($tmpPath);
            $io->error('Download failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        if ($remoteChecksum) {
            $downloadedChecksum = hash_file('sha256', $tmpPath);
            if ($downloadedChecksum !== $remoteChecksum) {
                @unlink($tmpPath);
                $io->error(sprintf(
                    'Checksum mismatch — expected %s, got %s. File rejected.',
                    $remoteChecksum,
                    $downloadedChecksum,
                ));
                return Command::FAILURE;
            }
            $io->text('SHA256 checksum verified.');
        } else {
            $io->note('No .sha256 file found at source — skipping integrity check.');
        }

        // Atomic swap
        if (file_exists($finalPath)) {
            @unlink($finalPath);
            @unlink($finalPath . '-wal');
            @unlink($finalPath . '-shm');
        }
        if (!rename($tmpPath, $finalPath)) {
            $io->error('Failed to move downloaded file into place.');
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Company registry installed: %s (%s)',
            $finalPath,
            $this->formatSize(filesize($finalPath)),
        ));

        return Command::SUCCESS;
    }

    private function fetchChecksum(string $url): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => 30]);
            if ($response->getStatusCode() !== 200) {
                return null;
            }
            $body = trim($response->getContent(false));
            // Accept either bare hex or "<hex>  filename" format
            $hex = preg_split('/\s+/', $body)[0] ?? '';
            return preg_match('/^[a-f0-9]{64}$/i', $hex) === 1 ? strtolower($hex) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
        return number_format($bytes / 1024, 0) . ' KB';
    }
}
