<?php

namespace App\Service\Export;

use App\Entity\Invoice;

class CsvExportService
{
    private const CSV_HEADERS = [
        'Numar',
        'Data emitere',
        'Data scadenta',
        'Tip',
        'Directie',
        'Status',
        'Emitent CIF',
        'Emitent',
        'Destinatar CIF',
        'Destinatar',
        'Subtotal',
        'TVA',
        'Total',
        'Moneda',
        'Platita la',
        'Metoda plata',
        'Duplicat',
        'Depusa cu intarziere',
    ];

    /**
     * @param Invoice[] $invoices
     */
    public function generate(array $invoices): string
    {
        $handle = fopen('php://temp', 'r+');

        // BOM for Excel UTF-8 compatibility
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, self::CSV_HEADERS);

        foreach ($invoices as $invoice) {
            fputcsv($handle, [
                $invoice->getNumber(),
                $invoice->getIssueDate()?->format('Y-m-d'),
                $invoice->getDueDate()?->format('Y-m-d'),
                $invoice->getDocumentType()->value,
                $invoice->getDirection()?->value,
                $invoice->getStatus()->value,
                $invoice->getSenderCif(),
                $invoice->getSenderName(),
                $invoice->getReceiverCif(),
                $invoice->getReceiverName(),
                $invoice->getSubtotal(),
                $invoice->getVatTotal(),
                $invoice->getTotal(),
                $invoice->getCurrency(),
                $invoice->getPaidAt()?->format('Y-m-d'),
                $invoice->getPaymentMethod(),
                $invoice->isDuplicate() ? 'Da' : 'Nu',
                $invoice->isLateSubmission() ? 'Da' : 'Nu',
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
