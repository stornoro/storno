<?php

namespace App\Service\Import;

use App\Entity\ImportJob;
use App\Service\Centrifugo\CentrifugoService;
use App\Service\Import\Mapper\ColumnMapperInterface;
use App\Service\Import\Parser\FileParserInterface;
use App\Service\Import\Persister\EntityPersisterInterface;
use App\Service\Import\Validator\ImportRowValidator;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;

class ImportOrchestrator
{
    private const CHANNEL_PREFIX = 'import:company_';
    private const PROGRESS_INTERVAL = 10; // Send progress every N rows

    /** @var FileParserInterface[] */
    private iterable $parsers;

    /** @var ColumnMapperInterface[] */
    private iterable $mappers;

    /** @var EntityPersisterInterface[] */
    private iterable $persisters;

    public function __construct(
        iterable $parsers,
        iterable $mappers,
        iterable $persisters,
        private readonly ImportRowValidator $validator,
        private readonly EntityManagerInterface $entityManager,
        private readonly FilesystemOperator $defaultStorage,
        private readonly CentrifugoService $centrifugo,
        private readonly LoggerInterface $logger,
    ) {
        // Convert iterables to arrays for reuse
        $this->parsers = $parsers instanceof \Traversable ? iterator_to_array($parsers) : $parsers;
        $this->mappers = $mappers instanceof \Traversable ? iterator_to_array($mappers) : $mappers;
        $this->persisters = $persisters instanceof \Traversable ? iterator_to_array($persisters) : $persisters;
    }

    /**
     * Prepare preview data for an import job (synchronous, fast).
     * Reads first 20 rows, detects columns, suggests mapper.
     */
    public function preparePreview(ImportJob $job): void
    {
        $parser = $this->getParser($job->getFileFormat());

        // Read file from storage to temp
        $tempPath = $this->downloadToTemp($job);

        try {
            $preview = $parser->preview($tempPath);
            $totalRows = $parser->countRows($tempPath);

            $job->setDetectedColumns($preview['headers']);
            $job->setPreviewData($preview['rows']);
            $job->setTotalRows($totalRows);

            // Try to find the best mapper and suggest mapping
            $mapper = $this->findBestMapper($job->getSource(), $job->getImportType(), $preview['headers']);
            if ($mapper) {
                $job->setSuggestedMapping($mapper->getDefaultMapping());
            }

            $job->setStatus('preview');
            $this->entityManager->flush();
        } finally {
            @unlink($tempPath);
        }
    }

    /**
     * Execute the full import (called async from Messenger handler).
     */
    public function executeImport(ImportJob $job): ImportResult
    {
        $result = new ImportResult();
        $company = $job->getCompany();
        $channel = self::CHANNEL_PREFIX . $company->getId()->toRfc4122();

        $parser = $this->getParser($job->getFileFormat());
        $mapper = $this->getMapper($job->getSource(), $job->getImportType());
        $persister = $this->getPersister($job->getImportType());

        $columnMapping = $job->getColumnMapping() ?? $mapper->getDefaultMapping();

        // Add source info for persister
        $sourceTag = $job->getSource();

        $tempPath = $this->downloadToTemp($job);
        $persister->reset();

        $result->setTotalRows($job->getTotalRows());
        $rowNumber = 0;

        try {
            // Send initial progress
            $this->sendProgress($channel, $job, $result, 'processing');

            foreach ($parser->parse($tempPath) as $rawRow) {
                $rowNumber++;

                try {
                    // Map the row
                    $mappedData = $mapper->mapRow($rawRow, $columnMapping);
                    $mappedData['_source'] = $sourceTag;
                    $mappedData['_importOptions'] = $job->getImportOptions() ?? [];

                    // Validate
                    $validationErrors = $this->validator->validate($mappedData, $mapper);
                    if (!empty($validationErrors)) {
                        foreach ($validationErrors as $field => $message) {
                            $result->addError($rowNumber, $field, $message);
                        }
                        continue;
                    }

                    // Persist
                    $persister->persist($mappedData, $company, $result);
                } catch (\Throwable $e) {
                    $result->addError($rowNumber, '_general', $e->getMessage());
                    $this->logger->warning('Import row error', [
                        'job' => $job->getId()->toRfc4122(),
                        'row' => $rowNumber,
                        'error' => $e->getMessage(),
                    ]);
                }

                // Send progress periodically
                if ($rowNumber % self::PROGRESS_INTERVAL === 0) {
                    $this->sendProgress($channel, $job, $result, 'processing');
                }
            }

            // Final flush
            $persister->flush();

            // Update job with results
            $job->setStatus('completed');
            $job->setCreatedCount($result->getCreatedCount());
            $job->setUpdatedCount($result->getUpdatedCount());
            $job->setSkippedCount($result->getSkippedCount());
            $job->setErrorCount($result->getErrorCount());
            $job->setErrors($result->getErrors());
            $job->setProcessedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            // Send final progress
            $this->sendProgress($channel, $job, $result, 'completed');

            $this->logger->info('Import completed', [
                'job' => $job->getId()->toRfc4122(),
                'created' => $result->getCreatedCount(),
                'updated' => $result->getUpdatedCount(),
                'skipped' => $result->getSkippedCount(),
                'errors' => $result->getErrorCount(),
            ]);
        } catch (\Throwable $e) {
            $job->setStatus('failed');
            $job->setErrors([['row' => 0, 'field' => '_general', 'message' => $e->getMessage()]]);
            $job->setProcessedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            $this->sendProgress($channel, $job, $result, 'failed');

            $this->logger->error('Import failed', [
                'job' => $job->getId()->toRfc4122(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            @unlink($tempPath);
            $persister->reset();
        }

        return $result;
    }

    /**
     * Get available sources with their supported import types and formats.
     */
    public function getAvailableSources(): array
    {
        $sources = [];

        foreach ($this->mappers as $mapper) {
            $source = $mapper->getSource();
            if (!isset($sources[$source])) {
                $sources[$source] = [
                    'key' => $source,
                    'importTypes' => [],
                    'formats' => $this->getFormatsForSource($source),
                ];
            }

            $importType = $mapper->getImportType();
            if (!in_array($importType, $sources[$source]['importTypes'], true)) {
                $sources[$source]['importTypes'][] = $importType;
            }
        }

        return array_values($sources);
    }

    /**
     * Auto-detect the best mapper for the given headers.
     */
    public function findBestMapper(string $source, string $importType, array $headers): ?ColumnMapperInterface
    {
        $bestMapper = null;
        $bestConfidence = 0.0;

        foreach ($this->mappers as $mapper) {
            if ($mapper->getImportType() !== $importType) {
                continue;
            }

            // Prefer exact source match
            if ($mapper->getSource() === $source) {
                $confidence = $mapper->detectConfidence($headers);
                if ($confidence > $bestConfidence) {
                    $bestConfidence = $confidence;
                    $bestMapper = $mapper;
                }
            }
        }

        // If no source-specific mapper found, try all mappers for this import type
        if (!$bestMapper || $bestConfidence < 0.3) {
            foreach ($this->mappers as $mapper) {
                if ($mapper->getImportType() !== $importType) {
                    continue;
                }
                $confidence = $mapper->detectConfidence($headers);
                if ($confidence > $bestConfidence) {
                    $bestConfidence = $confidence;
                    $bestMapper = $mapper;
                }
            }
        }

        return $bestMapper;
    }

    /**
     * Get target fields for a given import type (for UI column mapping).
     */
    public function getTargetFields(string $importType): array
    {
        foreach ($this->mappers as $mapper) {
            if ($mapper->getImportType() === $importType) {
                return $mapper->getTargetFields();
            }
        }
        return [];
    }

    private function getParser(string $fileFormat): FileParserInterface
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($fileFormat)) {
                return $parser;
            }
        }
        throw new \RuntimeException(sprintf('No parser found for format "%s"', $fileFormat));
    }

    private function getMapper(string $source, string $importType): ColumnMapperInterface
    {
        // Try exact match first
        foreach ($this->mappers as $mapper) {
            if ($mapper->getSource() === $source && $mapper->getImportType() === $importType) {
                return $mapper;
            }
        }
        // Fall back to generic
        foreach ($this->mappers as $mapper) {
            if ($mapper->getSource() === 'generic' && $mapper->getImportType() === $importType) {
                return $mapper;
            }
        }
        throw new \RuntimeException(sprintf('No mapper found for source "%s" type "%s"', $source, $importType));
    }

    private function getPersister(string $importType): EntityPersisterInterface
    {
        foreach ($this->persisters as $persister) {
            if ($persister->supports($importType)) {
                return $persister;
            }
        }
        throw new \RuntimeException(sprintf('No persister found for type "%s"', $importType));
    }

    private function downloadToTemp(ImportJob $job): string
    {
        $storagePath = $job->getStoragePath();
        $stream = $this->defaultStorage->readStream($storagePath);

        $ext = pathinfo($storagePath, PATHINFO_EXTENSION);
        $tempPath = sys_get_temp_dir() . '/' . uniqid('import_', true) . '.' . $ext;

        $tempStream = fopen($tempPath, 'w');
        stream_copy_to_stream($stream, $tempStream);
        fclose($tempStream);
        fclose($stream);

        return $tempPath;
    }

    private function getFormatsForSource(string $source): array
    {
        return match ($source) {
            'saga' => ['csv', 'xlsx', 'saga_xml'],
            'icefact' => ['csv'],
            'bolt' => ['csv'],
            'facturis' => ['csv', 'xlsx'],
            'emag' => ['xlsx'],
            default => ['csv', 'xlsx'],
        };
    }

    private function sendProgress(string $channel, ImportJob $job, ImportResult $result, string $status): void
    {
        try {
            $this->centrifugo->publish($channel, [
                'type' => 'import_progress',
                'jobId' => $job->getId()->toRfc4122(),
                'status' => $status,
                'totalRows' => $result->getTotalRows(),
                'processed' => $result->getProcessedCount(),
                'created' => $result->getCreatedCount(),
                'updated' => $result->getUpdatedCount(),
                'skipped' => $result->getSkippedCount(),
                'errors' => $result->getErrorCount(),
            ]);
        } catch (\Throwable $e) {
            // Non-critical, just log
            $this->logger->debug('Failed to send import progress', ['error' => $e->getMessage()]);
        }
    }
}
