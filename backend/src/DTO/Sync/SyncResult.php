<?php

namespace App\DTO\Sync;

class SyncResult
{
    /** @var string[] */
    private array $errors = [];

    public function __construct(
        private int $newInvoices = 0,
        private int $skippedDuplicates = 0,
        private int $newClients = 0,
        private int $newProducts = 0,
        private int $newSeries = 0,
    ) {}

    public function incrementNewInvoices(): void
    {
        $this->newInvoices++;
    }

    public function incrementSkippedDuplicates(): void
    {
        $this->skippedDuplicates++;
    }

    public function incrementNewClients(): void
    {
        $this->newClients++;
    }

    public function incrementNewProducts(): void
    {
        $this->newProducts++;
    }

    public function incrementNewSeries(): void
    {
        $this->newSeries++;
    }

    public function addError(string $error): void
    {
        $this->errors[] = $error;
    }

    public function getNewInvoices(): int
    {
        return $this->newInvoices;
    }

    public function getSkippedDuplicates(): int
    {
        return $this->skippedDuplicates;
    }

    public function getNewClients(): int
    {
        return $this->newClients;
    }

    public function getNewProducts(): int
    {
        return $this->newProducts;
    }

    public function getNewSeries(): int
    {
        return $this->newSeries;
    }

    /** @return string[] */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }

    public function toArray(): array
    {
        return [
            'newInvoices' => $this->newInvoices,
            'skippedDuplicates' => $this->skippedDuplicates,
            'newClients' => $this->newClients,
            'newProducts' => $this->newProducts,
            'newSeries' => $this->newSeries,
            'errors' => $this->errors,
        ];
    }
}
