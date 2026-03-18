<?php

namespace App\Service\Import;

use App\Entity\Company;
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
    private const PROGRESS_INTERVAL = 100; // Send progress every N rows
    private const CANCEL_CHECK_INTERVAL = 500; // Check for cancellation every N rows

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

        // Store IDs and scalar values so we can survive entityManager->clear()
        $sourceTag = $job->getSource();
        $importType = $job->getImportType();
        $importOptions = $job->getImportOptions() ?? [];
        $jobId = $job->getId();
        $companyId = $company->getId();

        $tempPath = $this->downloadToTemp($job);
        $persister->reset();

        $result->setTotalRows($job->getTotalRows());
        $rowNumber = 0;

        try {
            // Send initial progress
            $this->sendProgress($channel, $job, $result, 'processing', 0);

            foreach ($parser->parse($tempPath) as $rawRow) {
                $rowNumber++;

                try {
                    // Re-fetch references after entityManager->clear() in batch flush
                    if (!$this->entityManager->contains($job)) {
                        $job = $this->entityManager->getReference(ImportJob::class, $jobId);
                        $company = $this->entityManager->getReference(Company::class, $companyId);
                    }

                    // Map the row
                    $mappedData = $mapper->mapRow($rawRow, $columnMapping);
                    $mappedData['_source'] = $sourceTag;
                    $mappedData['_importType'] = $importType;
                    $mappedData['_importOptions'] = $importOptions;
                    $mappedData['_importJob'] = $job;

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
                    $this->sendProgress($channel, $job, $result, 'processing', $rowNumber);
                }

                // Check for cancellation periodically (raw SQL to avoid issues with detached entities)
                if ($rowNumber % self::CANCEL_CHECK_INTERVAL === 0) {
                    $status = $this->entityManager->getConnection()->fetchOne(
                        'SELECT status FROM import_job WHERE id = :id',
                        ['id' => $jobId->toRfc4122()],
                    );
                    if ($status === 'cancelled') {
                        $persister->flush();

                        $this->entityManager->getConnection()->executeStatement(
                            'UPDATE import_job SET status = :status, created_count = :created, updated_count = :updated, skipped_count = :skipped, error_count = :errors, processed_at = :processedAt WHERE id = :id',
                            [
                                'status' => 'cancelled',
                                'created' => $result->getCreatedCount(),
                                'updated' => $result->getUpdatedCount(),
                                'skipped' => $result->getSkippedCount(),
                                'errors' => $result->getErrorCount(),
                                'processedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                                'id' => $jobId->toRfc4122(),
                            ],
                        );

                        $this->sendProgress($channel, $job, $result, 'cancelled', $rowNumber);

                        $this->logger->info('Import cancelled by user', [
                            'job' => $jobId->toRfc4122(),
                            'processedRows' => $rowNumber,
                            'created' => $result->getCreatedCount(),
                        ]);

                        return $result;
                    }
                }
            }

            // Final flush
            $persister->flush();

            // Update job with results (raw SQL — entity may be detached after clear())
            $conn = $this->entityManager->getConnection();
            $conn->executeStatement(
                'UPDATE import_job SET status = :status, created_count = :created, updated_count = :updated, skipped_count = :skipped, error_count = :errors, errors = :errorList, processed_at = :processedAt WHERE id = :id',
                [
                    'status' => 'completed',
                    'created' => $result->getCreatedCount(),
                    'updated' => $result->getUpdatedCount(),
                    'skipped' => $result->getSkippedCount(),
                    'errors' => $result->getErrorCount(),
                    'errorList' => json_encode($result->getErrors()),
                    'processedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    'id' => $jobId->toRfc4122(),
                ],
            );

            // Send final progress
            $this->sendProgress($channel, $job, $result, 'completed', $rowNumber);

            $this->logger->info('Import completed', [
                'job' => $jobId->toRfc4122(),
                'created' => $result->getCreatedCount(),
                'updated' => $result->getUpdatedCount(),
                'skipped' => $result->getSkippedCount(),
                'errors' => $result->getErrorCount(),
            ]);
        } catch (\Throwable $e) {
            // Update job as failed (raw SQL for safety)
            try {
                $this->entityManager->getConnection()->executeStatement(
                    'UPDATE import_job SET status = :status, errors = :errors, processed_at = :processedAt WHERE id = :id',
                    [
                        'status' => 'failed',
                        'errors' => json_encode([['row' => 0, 'field' => '_general', 'message' => $e->getMessage()]]),
                        'processedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                        'id' => $jobId->toRfc4122(),
                    ],
                );
            } catch (\Throwable) {
                // Connection may be broken — just log
            }

            $this->sendProgress($channel, $job, $result, 'failed', $rowNumber);

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

    private function sendProgress(string $channel, ImportJob $job, ImportResult $result, string $status, int $rowNumber = 0): void
    {
        try {
            $this->centrifugo->publish($channel, [
                'type' => 'import_progress',
                'jobId' => $job->getId()->toRfc4122(),
                'status' => $status,
                'totalRows' => $result->getTotalRows(),
                'processed' => $rowNumber,
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
