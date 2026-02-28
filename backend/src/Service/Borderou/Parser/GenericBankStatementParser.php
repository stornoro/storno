<?php

namespace App\Service\Borderou\Parser;

class GenericBankStatementParser implements BorderouParserInterface
{
    public function getProvider(): string
    {
        return 'generic_bank';
    }

    public function getSourceType(): string
    {
        return 'bank_statement';
    }

    public function getSupportedFormats(): array
    {
        return ['csv', 'xlsx', 'xls'];
    }

    public function detectConfidence(array $headers): float
    {
        $normalised = array_map(fn (string $h) => mb_strtolower(trim($h)), $headers);

        $bankIndicators = 0;

        foreach ($normalised as $h) {
            if (str_contains($h, 'referint') || str_contains($h, 'referinta') || str_contains($h, 'reference')) {
                $bankIndicators++;
            }
            if (str_contains($h, 'credit') || str_contains($h, 'debit')) {
                $bankIndicators++;
            }
            if (str_contains($h, 'descriere') || str_contains($h, 'description') || str_contains($h, 'detalii')) {
                $bankIndicators++;
            }
            if (str_contains($h, 'data') || str_contains($h, 'date')) {
                $bankIndicators++;
            }
            if (str_contains($h, 'suma') || str_contains($h, 'amount') || str_contains($h, 'valoare')) {
                $bankIndicators++;
            }
        }

        if ($bankIndicators >= 3) {
            return 0.6;
        }

        return 0.1;
    }

    public function parseRows(array $headers, iterable $rows): array
    {
        $headerMap = $this->buildHeaderMap($headers);
        $transactions = [];

        foreach ($rows as $row) {
            $amount = $this->getField($row, $headerMap, 'credit');
            if (empty($amount) || $this->parseAmount($amount) === '0.00') {
                // If no credit column, try generic amount
                $amount = $this->getField($row, $headerMap, 'amount');
            }

            $parsedAmount = $this->parseAmount($amount);
            if (bccomp($parsedAmount, '0', 2) <= 0) {
                continue;
            }

            $description = $this->getField($row, $headerMap, 'description');
            $reference = $this->getField($row, $headerMap, 'reference');
            $date = $this->getField($row, $headerMap, 'date');
            $currency = $this->getField($row, $headerMap, 'currency') ?: 'RON';

            // Try to extract invoice number from description
            $invoiceRef = $this->extractInvoiceReference($description);

            $explanation = implode(' | ', array_filter([$description, $reference ? 'Ref: ' . $reference : null]));

            $transactions[] = [
                'date' => $this->parseDate($date),
                'clientName' => $this->extractClientName($description),
                'clientCif' => $this->extractCif($description),
                'explanation' => $explanation,
                'amount' => $parsedAmount,
                'currency' => strtoupper($currency),
                'awbNumber' => null,
                'bankReference' => $reference ?: null,
                'documentType' => 'transfer',
                'documentNumber' => $invoiceRef,
                'rawData' => $row,
            ];
        }

        return $transactions;
    }

    private function buildHeaderMap(array $headers): array
    {
        $map = [];
        $normalised = array_map(fn (string $h) => mb_strtolower(trim($h)), $headers);

        $mappings = [
            'date' => ['data', 'date', 'data tranzactie', 'data operatiune', 'data valuta'],
            'description' => ['descriere', 'description', 'detalii', 'detalii tranzactie', 'explicatie', 'referinta tranzactie'],
            'reference' => ['referinta', 'reference', 'ref', 'nr. referinta', 'id tranzactie'],
            'credit' => ['credit', 'incasare', 'suma credit'],
            'amount' => ['suma', 'amount', 'valoare', 'total'],
            'currency' => ['moneda', 'currency', 'valuta', 'ccy'],
        ];

        foreach ($mappings as $key => $candidates) {
            // First pass: exact match
            foreach ($candidates as $candidate) {
                $idx = array_search($candidate, $normalised, true);
                if ($idx !== false) {
                    $map[$key] = $headers[$idx];
                    break;
                }
            }

            // Second pass: partial match (for verbose headers like "Credit (suma)")
            if (!isset($map[$key])) {
                foreach ($candidates as $candidate) {
                    foreach ($normalised as $idx => $normalHeader) {
                        if (str_starts_with($normalHeader, $candidate . ' ') || str_starts_with($normalHeader, $candidate . '(')) {
                            $map[$key] = $headers[$idx];
                            break 2;
                        }
                    }
                }
            }
        }

        return $map;
    }

    private function getField(array $row, array $headerMap, string $field): string
    {
        if (!isset($headerMap[$field])) {
            return '';
        }

        return trim($row[$headerMap[$field]] ?? '');
    }

    private function parseAmount(string $value): string
    {
        $value = str_replace([' ', "\xC2\xA0"], '', $value);

        if ($value === '' || $value === '0' || $value === '0.00') {
            return '0.00';
        }

        // Detect number format:
        // "4,113.20" → comma=thousands, period=decimal (EN/BCR format)
        // "4.113,20" → period=thousands, comma=decimal (EU format)
        // "4113.20"  → no thousands separator
        // "4113,20"  → comma=decimal (EU simple)
        $hasComma = str_contains($value, ',');
        $hasDot = str_contains($value, '.');

        if ($hasComma && $hasDot) {
            $lastComma = strrpos($value, ',');
            $lastDot = strrpos($value, '.');

            if ($lastComma > $lastDot) {
                // "4.113,20" → EU format: period=thousands, comma=decimal
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                // "4,113.20" → EN format: comma=thousands, period=decimal
                $value = str_replace(',', '', $value);
            }
        } elseif ($hasComma) {
            // "4113,20" → comma=decimal
            $value = str_replace(',', '.', $value);
        }
        // else: "4113.20" or no separator → already fine

        $value = preg_replace('/[^0-9.\-]/', '', $value);

        if ($value === '' || !is_numeric($value)) {
            return '0.00';
        }

        $num = (float) $value;
        if ($num < 0) {
            return '0.00'; // Skip debits
        }

        return number_format($num, 2, '.', '');
    }

    private function parseDate(string $value): string
    {
        $value = trim($value);
        if (empty($value)) {
            return date('Y-m-d');
        }

        if (preg_match('#^(\d{1,2})[./\-](\d{1,2})[./\-](\d{4})$#', $value, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }

        if (preg_match('#^\d{4}-\d{2}-\d{2}$#', $value)) {
            return $value;
        }

        return date('Y-m-d');
    }

    /**
     * Try to extract an invoice number from bank description text.
     * Common patterns: "Fact TR0016", "Factura AUT-0001", "fact. nr. 123"
     */
    private function extractInvoiceReference(string $description): ?string
    {
        // Match: fact/factura followed by optional delimiters and an alphanumeric reference
        if (preg_match('/fact(?:ura)?\.?\s*(?:nr\.?\s*)?([A-Z0-9][\w\-\/]*\d+)/i', $description, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Try to extract CIF from description (RO + digits, or just 6-10 digit number after CUI/CIF label).
     */
    private function extractCif(string $description): ?string
    {
        if (preg_match('/\b(?:CUI|CIF|C\.?U\.?I\.?)\s*:?\s*(?:RO)?(\d{6,10})\b/i', $description, $m)) {
            return $m[1];
        }

        if (preg_match('/\bRO\s*(\d{6,10})\b/', $description, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Try to extract a client/company name from description.
     * Very heuristic — looks for patterns like "de la <NAME>" or "platitor <NAME>".
     */
    private function extractClientName(string $description): ?string
    {
        if (preg_match('/(?:de la|platitor|beneficiar|ordonator)\s*:?\s*(.+?)(?:\s*(?:CUI|CIF|IBAN|$))/i', $description, $m)) {
            $name = trim($m[1]);
            if (strlen($name) > 3) {
                return $name;
            }
        }

        return null;
    }
}
