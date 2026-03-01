<?php

namespace App\Service\Borderou\Parser;

/**
 * Borderou parser for eMag marketplace sales XLSX exports.
 *
 * Multiple rows share the same "Serie factura" + "Numar factura" — each row
 * represents one product line. This parser groups rows by composite invoice
 * number and sums the gross totals, producing one transaction per invoice.
 *
 * The composite number (e.g. "FV 12345") is set as `documentNumber` so the
 * matching service can look it up directly via invoice number.
 */
class EmagMarketplaceParser implements BorderouParserInterface
{
    public function getProvider(): string
    {
        return 'emag';
    }

    public function getSourceType(): string
    {
        return 'marketplace';
    }

    public function getSupportedFormats(): array
    {
        return ['xlsx'];
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
     * @param string[] $headers
     * @param iterable<array<string, string>> $rows
     * @return array<int, array{date: string, clientName: ?string, clientCif: ?string, explanation: string, amount: string, currency: string, awbNumber: ?string, bankReference: ?string, documentType: ?string, documentNumber: ?string, rawData: array}>
     */
    public function parseRows(array $headers, iterable $rows): array
    {
        // First pass: group rows by composite invoice number
        $groups = [];

        foreach ($rows as $row) {
            $serie = trim($row['Serie factura'] ?? '');
            $numar = trim($row['Numar factura'] ?? '');
            $compositeNumber = trim("$serie $numar");

            if ($compositeNumber === '') {
                continue;
            }

            $amount = $this->parseAmount($row['Valoare cu TVA'] ?? '0');
            if (bccomp($amount, '0', 2) <= 0) {
                continue;
            }

            $key = mb_strtolower($compositeNumber);

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'compositeNumber' => $compositeNumber,
                    'date' => $row['Data'] ?? '',
                    'clientName' => $row['Cumparator (Denumire forma juridica)'] ?? ($row['Cumparator'] ?? null),
                    'clientCif' => $row['CNP / CUI'] ?? null,
                    'currency' => strtoupper(trim($row['Moneda'] ?? '')) ?: 'RON',
                    'amount' => 0.0,
                    'orderNumbers' => [],
                    'products' => [],
                    'firstRow' => $row,
                ];
            }

            $groups[$key]['amount'] += (float) $amount;

            // Collect unique order numbers
            $orderNumber = trim($row['Numar comanda'] ?? '');
            if ($orderNumber !== '' && !in_array($orderNumber, $groups[$key]['orderNumbers'], true)) {
                $groups[$key]['orderNumbers'][] = $orderNumber;
            }

            // Collect product descriptions for explanation
            $product = trim($row['Denumire produs'] ?? '');
            $qty = trim($row['Cantitate'] ?? '1');
            if ($product !== '') {
                $groups[$key]['products'][] = $qty . 'x ' . $product;
            }
        }

        // Second pass: build standardized transactions
        $transactions = [];

        foreach ($groups as $group) {
            $orderStr = implode(', ', $group['orderNumbers']);
            $productStr = implode('; ', array_slice($group['products'], 0, 5));
            if (count($group['products']) > 5) {
                $productStr .= ' (+' . (count($group['products']) - 5) . ')';
            }

            $explanation = implode(' | ', array_filter([
                'eMag ' . $group['compositeNumber'],
                $orderStr ? 'Comenzi: ' . $orderStr : null,
                $productStr,
            ]));

            $transactions[] = [
                'date' => $this->parseDate($group['date']),
                'clientName' => $group['clientName'] ?: null,
                'clientCif' => $group['clientCif'] ?: null,
                'explanation' => $explanation,
                'amount' => number_format($group['amount'], 2, '.', ''),
                'currency' => $group['currency'],
                'awbNumber' => null,
                'bankReference' => $orderStr ?: null,
                'documentType' => 'marketplace',
                'documentNumber' => $group['compositeNumber'],
                'rawData' => $group['firstRow'],
            ];
        }

        return $transactions;
    }

    private function parseAmount(string $value): string
    {
        $value = str_replace([' ', "\xC2\xA0"], '', $value);

        if ($value === '' || $value === '0' || $value === '0.00') {
            return '0.00';
        }

        $hasComma = str_contains($value, ',');
        $hasDot = str_contains($value, '.');

        if ($hasComma && $hasDot) {
            $lastComma = strrpos($value, ',');
            $lastDot = strrpos($value, '.');

            if ($lastComma > $lastDot) {
                // EU format: "4.113,20"
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                // EN format: "4,113.20"
                $value = str_replace(',', '', $value);
            }
        } elseif ($hasComma) {
            $value = str_replace(',', '.', $value);
        }

        $value = preg_replace('/[^0-9.\-]/', '', $value);

        if ($value === '' || !is_numeric($value)) {
            return '0.00';
        }

        $num = (float) $value;
        if ($num < 0) {
            return '0.00';
        }

        return number_format($num, 2, '.', '');
    }

    private function parseDate(string $value): string
    {
        $value = trim($value);
        if (empty($value)) {
            return date('Y-m-d');
        }

        // DD.MM.YYYY or DD/MM/YYYY
        if (preg_match('#^(\d{1,2})[./\-](\d{1,2})[./\-](\d{4})$#', $value, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }

        // YYYY-MM-DD
        if (preg_match('#^\d{4}-\d{2}-\d{2}$#', $value)) {
            return $value;
        }

        // Excel serial date
        if (is_numeric($value) && (int) $value > 40000 && (int) $value < 60000) {
            $date = \DateTime::createFromFormat('U', (string) (((int) $value - 25569) * 86400));
            if ($date) {
                return $date->format('Y-m-d');
            }
        }

        return date('Y-m-d');
    }
}
