<?php

namespace App\Service\Import\Mapper;

/**
 * Column mapper for Facturis sales journal XLSX/CSV exports.
 *
 * Multiple rows share the same "Nr. Document" — each row represents one line
 * item on the invoice. The InvoicePersister accumulates lines with the same
 * dedup key within a batch.
 *
 * Direction: issued (company → client).
 * Distinctive columns: "Nr. Document", "Nume Client", "Cod Fiscal", "Pret fara TVA", "Cota TVA".
 */
class FacturisInvoiceMapper extends AbstractInvoiceMapper
{
    public function getSource(): string
    {
        return 'facturis';
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
            'Nr. Document'   => 'number',
            'Nume Client'    => 'receiverName',
            'Cod Fiscal'     => 'receiverCif',
            'Data'           => 'issueDate',
            'Mod de Plata'   => 'paymentMethod',
            'Moneda'         => 'currency',
            'Observatii 1'   => 'notes',
            'Cod Produs'     => 'lineProductCode',
            'Nume produs'    => 'lineDescription',
            'UM'             => 'lineUnitOfMeasure',
            'Cantitate'      => 'lineQuantity',
            'Pret fara TVA'  => 'lineUnitPrice',
            'Valoare fara TVA' => 'lineTotal',
            'TVA'            => 'lineVatAmount',
            'Cota TVA'       => 'lineVatRate',
        ];
    }

    /**
     * @param string[] $headers
     */
    public function detectConfidence(array $headers): float
    {
        $anchors = [
            'Nr. Document',
            'Nume Client',
            'Cod Fiscal',
            'Pret fara TVA',
            'Cota TVA',
        ];

        return $this->ratioFound($anchors, $headers);
    }

    /**
     * @param array<string, string> $row
     * @param array<string, string> $columnMapping
     * @return array<string, mixed>
     */
    public function mapRow(array $row, array $columnMapping): array
    {
        $result = parent::mapRow($row, $columnMapping);

        $result['direction'] = 'issued';

        // Compute invoice-level totals from line data (will be accumulated by persister)
        $lines = $result['lines'] ?? [];
        if (!empty($lines)) {
            $lineTotal = (float) ($lines[0]['total'] ?? $lines[0]['unitPrice'] ?? 0);
            $lineVat = (float) ($lines[0]['vatAmount'] ?? 0);
            $result['subtotal'] = $result['subtotal'] ?? (string) $lineTotal;
            $result['vatTotal'] = $result['vatTotal'] ?? (string) $lineVat;
            $result['total'] = $result['total'] ?? (string) ($lineTotal + $lineVat);
        }

        return $result;
    }
}
