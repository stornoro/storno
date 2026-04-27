<?php

namespace App\DTO\Sync;

use App\Entity\Invoice;

class SyncResult
{
    /** @var string[] */
    private array $errors = [];

    /**
     * Lightweight per-invoice summaries captured during the sync.
     * Used to build descriptive notifications instead of bare counts.
     *
     * @var list<array{id: string, number: ?string, direction: ?string, total: string, currency: string, senderName: ?string, receiverName: ?string}>
     */
    private array $newInvoiceSummaries = [];

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

    /**
     * Record a freshly synced invoice with enough info to drive a rich notification.
     */
    public function recordNewInvoice(Invoice $invoice): void
    {
        $this->newInvoices++;
        $this->newInvoiceSummaries[] = [
            'id' => (string) $invoice->getId(),
            'number' => $invoice->getNumber(),
            'direction' => $invoice->getDirection()?->value,
            'total' => $invoice->getTotal(),
            'currency' => $invoice->getCurrency(),
            'senderName' => $invoice->getSenderName(),
            'receiverName' => $invoice->getReceiverName(),
        ];
    }

    /**
     * @return list<array{id: string, number: ?string, direction: ?string, total: string, currency: string, senderName: ?string, receiverName: ?string}>
     */
    public function getNewInvoiceSummaries(): array
    {
        return $this->newInvoiceSummaries;
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
