<?php

namespace App\Service\Import;

class ImportResult
{
    /** @var array<int, array{row: int, field: string, message: string}> */
    private array $errors = [];

    public function __construct(
        private int $totalRows = 0,
        private int $createdCount = 0,
        private int $updatedCount = 0,
        private int $skippedCount = 0,
        private int $errorCount = 0,
    ) {}

    public function incrementCreated(): void
    {
        $this->createdCount++;
    }

    public function incrementUpdated(): void
    {
        $this->updatedCount++;
    }

    public function incrementSkipped(): void
    {
        $this->skippedCount++;
    }

    public function addError(int $row, string $field, string $message): void
    {
        $this->errors[] = ['row' => $row, 'field' => $field, 'message' => $message];
        $this->errorCount++;
    }

    public function setTotalRows(int $total): void
    {
        $this->totalRows = $total;
    }

    public function getTotalRows(): int
    {
        return $this->totalRows;
    }

    public function getCreatedCount(): int
    {
        return $this->createdCount;
    }

    public function getUpdatedCount(): int
    {
        return $this->updatedCount;
    }

    public function getSkippedCount(): int
    {
        return $this->skippedCount;
    }

    public function getErrorCount(): int
    {
        return $this->errorCount;
    }

    /** @return array<int, array{row: int, field: string, message: string}> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getProcessedCount(): int
    {
        return $this->createdCount + $this->updatedCount + $this->skippedCount + $this->errorCount;
    }

    public function hasErrors(): bool
    {
        return $this->errorCount > 0;
    }

    public function toArray(): array
    {
        return [
            'totalRows' => $this->totalRows,
            'createdCount' => $this->createdCount,
            'updatedCount' => $this->updatedCount,
            'skippedCount' => $this->skippedCount,
            'errorCount' => $this->errorCount,
            'errors' => $this->errors,
        ];
    }
}
