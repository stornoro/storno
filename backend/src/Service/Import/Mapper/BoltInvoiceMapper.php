<?php

namespace App\Service\Import\Mapper;

/**
 * Column mapper for Bolt ride-sharing invoice CSV exports.
 *
 * Each CSV row represents a single received invoice (Bolt → user's company).
 * Distinctive columns: "Factura numărul", "Valoare totală", "Număr TVA beneficiar".
 */
class BoltInvoiceMapper extends AbstractInvoiceMapper
{
    public function getSource(): string
    {
        return 'bolt';
    }

    public function getImportType(): string
    {
        return 'invoices_received';
    }

    /**
     * @return array<string, string>
     */
    public function getDefaultMapping(): array
    {
        return [
            'Factura numărul'         => 'number',
            'Data'                    => 'issueDate',
            'Beneficiar'              => 'senderName',
            'Număr TVA beneficiar'    => 'senderCif',
            'Nume firma'              => 'receiverName',
            'Cod unic de inregistrare' => 'receiverCif',
            'Valoare (fără TVA)'      => 'subtotal',
            'TVA'                     => 'vatTotal',
            'Valoare totală'          => 'total',
            'Metoda de plată'         => 'paymentMethod',
        ];
    }

    /**
     * @param string[] $headers
     */
    public function detectConfidence(array $headers): float
    {
        $anchors = [
            'Factura numărul',
            'Valoare totală',
            'Număr TVA beneficiar',
            'Cod unic de inregistrare',
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

        $result['direction'] = 'received';
        $result['currency'] = $result['currency'] ?? 'RON';

        // Build a single line from the invoice totals + ride context
        $driver = $row['Șofer'] ?? $row['Sofer'] ?? '';
        $rideDate = $row['Data călătoriei'] ?? $row['Data calatoriei'] ?? '';

        $description = 'Serviciu transport';
        if ($driver !== '') {
            $description .= " - $driver";
        }
        if ($rideDate !== '') {
            $description .= " ($rideDate)";
        }

        $result['lines'] = [[
            'description'   => $description,
            'quantity'      => '1',
            'unitOfMeasure' => 'buc',
            'unitPrice'     => $result['subtotal'] ?? '0',
            'vatRate'       => '19',
            'vatAmount'     => $result['vatTotal'] ?? '0',
            'lineTotal'     => $result['subtotal'] ?? '0',
        ]];

        return $result;
    }
}
