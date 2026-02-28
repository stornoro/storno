<?php

namespace App\Service\Import\Mapper;

/**
 * Column mapper for eMag marketplace sales XLSX exports.
 *
 * Multiple rows share the same "Serie factura" + "Numar factura" — each row
 * represents one line item. The InvoicePersister accumulates lines with the
 * same dedup key within a batch.
 *
 * Direction: issued (company → marketplace customer).
 * Distinctive columns: "Numar comanda", "Serie factura", "Numar factura", "Cumparator".
 */
class EmagInvoiceMapper extends AbstractInvoiceMapper
{
    public function getSource(): string
    {
        return 'emag';
    }

    public function getImportType(): string
    {
        return 'invoices_issued';
    }

    /**
     * @return array<string, string>
     */
    public function getDefaultMapping(): array
    {
        return [
            'Numar factura'                       => 'number',
            'Data'                                => 'issueDate',
            'Cumparator (Denumire forma juridica)' => 'receiverName',
            'CNP / CUI'                           => 'receiverCif',
            'Part number'                         => 'lineProductCode',
            'Denumire produs'                     => 'lineDescription',
            'Cantitate'                           => 'lineQuantity',
            'Valoare fara TVA'                    => 'lineTotal',
            'Cota TVA%'                           => 'lineVatRate',
        ];
    }

    /**
     * @param string[] $headers
     */
    public function detectConfidence(array $headers): float
    {
        $anchors = [
            'Numar comanda',
            'Serie factura',
            'Numar factura',
            'Cumparator',
        ];

        // eMag may use "Cumparator" as prefix in longer header names, so check with contains
        $normalizedHeaders = array_map('mb_strtolower', $headers);
        $found = 0;

        foreach ($anchors as $anchor) {
            $anchorLower = mb_strtolower($anchor);
            foreach ($normalizedHeaders as $header) {
                if (str_contains($header, $anchorLower)) {
                    $found++;
                    break;
                }
            }
        }

        return count($anchors) > 0 ? round($found / count($anchors), 2) : 0.0;
    }

    /**
     * @param array<string, string> $row
     * @param array<string, string> $columnMapping
     * @return array<string, mixed>
     */
    public function mapRow(array $row, array $columnMapping): array
    {
        // Build composite number from Serie + Numar before parent mapping
        $serie = $row['Serie factura'] ?? '';
        $numar = $row['Numar factura'] ?? '';
        $compositeNumber = trim("$serie $numar");

        $result = parent::mapRow($row, $columnMapping);

        // Override number with composite
        if ($compositeNumber !== '') {
            $result['number'] = $compositeNumber;
        }

        $result['direction'] = 'issued';
        $result['currency'] = $result['currency'] ?? 'RON';

        // Compute VAT amount from line values
        $lines = $result['lines'] ?? [];
        if (!empty($lines)) {
            $netVal = $this->normalizeAmount($row['Valoare fara TVA'] ?? '0');
            $grossVal = $this->normalizeAmount($row['Valoare cu TVA'] ?? $netVal);
            $vatAmount = (string) ((float) $grossVal - (float) $netVal);

            $lines[0]['unitPrice'] = $netVal;
            $lines[0]['lineTotal'] = $netVal;
            $lines[0]['vatAmount'] = $vatAmount;
            $lines[0]['description'] = $lines[0]['description'] ?? ($lines[0]['productCode'] ?? 'Produs eMag');
            $lines[0]['quantity'] = $lines[0]['quantity'] ?? '1';
            $lines[0]['unitOfMeasure'] = $lines[0]['unitOfMeasure'] ?? 'buc';
            $result['lines'] = $lines;
        }

        $netTotal = $this->normalizeAmount($row['Valoare fara TVA'] ?? '0');
        $grossTotal = $this->normalizeAmount($row['Valoare cu TVA'] ?? $netTotal);
        $result['subtotal'] = $netTotal;
        $result['vatTotal'] = (string) ((float) $grossTotal - (float) $netTotal);
        $result['total'] = $grossTotal;

        return $result;
    }
}
