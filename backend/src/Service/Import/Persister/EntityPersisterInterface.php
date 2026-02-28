<?php

namespace App\Service\Import\Persister;

use App\Entity\Company;
use App\Service\Import\ImportResult;

interface EntityPersisterInterface
{
    /**
     * Which import type this persister handles.
     */
    public function supports(string $importType): bool;

    /**
     * Persist a single mapped row. Handles deduplication internally.
     *
     * @param array<string, mixed> $mappedData
     */
    public function persist(array $mappedData, Company $company, ImportResult $result): void;

    /**
     * Flush any batched entities and clear entity manager for memory management.
     */
    public function flush(): void;

    /**
     * Reset internal dedup caches (call before starting a new import).
     */
    public function reset(): void;
}
