<?php

namespace App\Service\Import\Mapper;

abstract class AbstractInvoiceMapper implements ColumnMapperInterface
{
    abstract public function getImportType(): string;

    public function getRequiredFields(): array
    {
        return ['number'];
    }

    /**
     * @return array<string, string>
     */
    public function getTargetFields(): array
    {
        return [
            'number'             => 'Nr. factura',
            'issueDate'          => 'Data emitere',
            'dueDate'            => 'Data scadenta',
            'senderName'         => 'Furnizor (nume)',
            'senderCif'          => 'Furnizor (CIF)',
            'receiverName'       => 'Client (nume)',
            'receiverCif'        => 'Client (CIF)',
            'subtotal'           => 'Valoare fara TVA',
            'vatTotal'           => 'TVA',
            'total'              => 'Total',
            'currency'           => 'Moneda',
            'paymentMethod'      => 'Metoda plata',
            'notes'              => 'Observatii',
            // Line fields (prefixed for UI mapping display)
            'lineDescription'    => 'Produs / Descriere',
            'lineQuantity'       => 'Cantitate',
            'lineUnitOfMeasure'  => 'UM',
            'lineUnitPrice'      => 'Pret unitar',
            'lineVatRate'        => 'Cota TVA',
            'lineVatAmount'      => 'TVA linie',
            'lineTotal'          => 'Total linie',
            'lineProductCode'    => 'Cod produs',
        ];
    }

    /**
     * @param array<string, string> $row
     * @param array<string, string> $columnMapping
     * @return array<string, mixed>
     */
    public function mapRow(array $row, array $columnMapping): array
    {
        $result = [];
        $lineData = [];

        foreach ($columnMapping as $sourceCol => $targetField) {
            if ($targetField === '' || $targetField === null) {
                continue;
            }

            $value = $row[$sourceCol] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            if (str_starts_with($targetField, 'line')) {
                // Line-level field: strip 'line' prefix, lowercase first char
                $lineField = lcfirst(substr($targetField, 4));
                $lineData[$lineField] = $value;
            } else {
                $result[$targetField] = $value;
            }
        }

        // Build lines array
        if (!empty($lineData)) {
            $result['lines'] = [$lineData];
        }

        // Amount normalisation (handle both comma and dot decimals)
        foreach (['subtotal', 'vatTotal', 'total'] as $field) {
            if (isset($result[$field])) {
                $result[$field] = $this->normalizeAmount($result[$field]);
            }
        }
        foreach (($result['lines'] ?? []) as &$line) {
            foreach (['unitPrice', 'vatAmount', 'total', 'quantity'] as $f) {
                if (isset($line[$f])) {
                    $line[$f] = $this->normalizeAmount($line[$f]);
                }
            }
        }
        unset($line);

        return $result;
    }

    protected function normalizeAmount(string $value): string
    {
        // Strip spaces and non-breaking spaces
        $value = str_replace([' ', "\xC2\xA0"], '', $value);

        // European format: 1.234,56 → 1234,56
        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = str_replace('.', '', $value);
        }

        // Comma decimal → dot decimal
        return str_replace(',', '.', $value);
    }

    /**
     * Return the ratio of expected anchor columns found in the given headers.
     *
     * @param string[] $anchors
     * @param string[] $headers
     */
    protected function ratioFound(array $anchors, array $headers): float
    {
        if ($anchors === []) {
            return 0.0;
        }

        $normalizedHeaders = array_map('mb_strtolower', $headers);
        $found = 0;
        foreach ($anchors as $anchor) {
            if (in_array(mb_strtolower($anchor), $normalizedHeaders, true)) {
                $found++;
            }
        }

        return round($found / count($anchors), 2);
    }
}
