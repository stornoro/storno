<?php

namespace App\Service\Anaf;

class SeriesDetector
{
    /**
     * Detect series prefix and number from an invoice number string.
     *
     * Examples: "WAS0001" -> ['prefix' => 'WAS', 'number' => 1]
     *           "FCT-123" -> ['prefix' => 'FCT', 'number' => 123]
     *
     * @return array{prefix: string, number: int}|null
     */
    public function detect(string $invoiceNumber): ?array
    {
        $invoiceNumber = trim($invoiceNumber);

        if ($invoiceNumber === '' || $invoiceNumber === 'N/A') {
            return null;
        }

        if (!preg_match('/^([A-Za-z]{1,10})[-]?(\d+)$/', $invoiceNumber, $matches)) {
            return null;
        }

        $prefix = strtoupper($matches[1]);
        $number = (int) ltrim($matches[2], '0') ?: 0;

        return [
            'prefix' => $prefix,
            'number' => $number,
        ];
    }
}
