<?php

namespace App\Service\Export;

use App\Entity\Invoice;
use App\Service\InvoiceXmlResolver;
use App\Service\PdfGeneratorService;
use League\Flysystem\FilesystemOperator;

class ZipExportService
{
    public function __construct(
        private readonly FilesystemOperator $defaultStorage,
        private readonly PdfGeneratorService $pdfGenerator,
        private readonly InvoiceXmlResolver $xmlResolver,
    ) {}

    /**
     * @param Invoice[] $invoices
     */
    public function generate(array $invoices): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'invoice_export_') . '.zip';
        $zip = new \ZipArchive();

        if ($zip->open($tmpFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Failed to create ZIP archive');
        }

        foreach ($invoices as $invoice) {
            $prefix = $this->sanitizeFilename($invoice->getNumber() ?? (string) $invoice->getId());

            // Add XML
            $xml = $this->getXmlContent($invoice);
            if ($xml) {
                $zip->addFromString("{$prefix}/{$prefix}.xml", $xml);
            }

            // Add signature
            $signature = $invoice->getSignatureContent();
            if ($signature) {
                $zip->addFromString("{$prefix}/{$prefix}.p7s", $signature);
            }

            // Add PDF (generate from XML if not cached)
            $pdf = $this->getPdfContent($invoice, $xml);
            if ($pdf) {
                $zip->addFromString("{$prefix}/{$prefix}.pdf", $pdf);
            }
        }

        $zip->close();

        if (!file_exists($tmpFile)) {
            return '';
        }

        $content = file_get_contents($tmpFile);
        unlink($tmpFile);

        return $content;
    }

    private function getXmlContent(Invoice $invoice): ?string
    {
        return $this->xmlResolver->resolve($invoice);
    }

    private function getPdfContent(Invoice $invoice, ?string $xml): ?string
    {
        // Try cached PDF first
        $pdfPath = $invoice->getPdfPath();
        if ($pdfPath && $this->defaultStorage->fileExists($pdfPath)) {
            return $this->defaultStorage->read($pdfPath);
        }

        // Generate from XML
        if ($xml) {
            try {
                return $this->pdfGenerator->generatePdf($xml);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function sanitizeFilename(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);
    }
}
