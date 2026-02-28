<?php

namespace App\Service;

use App\Entity\Invoice;
use App\Service\Anaf\UblXmlGenerator;
use League\Flysystem\FilesystemOperator;

/**
 * Resolves the XML content for an invoice.
 * Reads from Flysystem storage if available, otherwise generates on the fly.
 */
class InvoiceXmlResolver
{
    public function __construct(
        private readonly FilesystemOperator $defaultStorage,
        private readonly UblXmlGenerator $xmlGenerator,
    ) {}

    public function resolve(Invoice $invoice): ?string
    {
        // 1. Try stored XML in Flysystem
        $xmlPath = $invoice->getXmlPath();
        if ($xmlPath && $this->defaultStorage->fileExists($xmlPath)) {
            return $this->defaultStorage->read($xmlPath);
        }

        // 2. Generate on the fly
        try {
            return $this->xmlGenerator->generate($invoice);
        } catch (\Throwable) {
            return null;
        }
    }
}
