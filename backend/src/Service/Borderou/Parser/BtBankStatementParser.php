<?php

namespace App\Service\Borderou\Parser;

class BtBankStatementParser implements BorderouParserInterface
{
    private const COL_TX_DATE = 'Data tranzactie';
    private const COL_VALUE_DATE = 'Data valuta';
    private const COL_REFERENCE = 'Referinta';
    private const COL_TX_TYPE = 'Tip tranzactie';
    private const COL_DESCRIPTION = 'Descriere';
    private const COL_DEBIT = 'Debit';
    private const COL_CREDIT = 'Credit';

    private array $metadata = [];

    public function getProvider(): string
    {
        return 'bt';
    }

    public function getSourceType(): string
    {
        return 'bank_statement';
    }

    public function getSupportedFormats(): array
    {
        return ['csv', 'xlsx'];
    }

    public function detectConfidence(array $headers): float
    {
        $normalised = array_map(fn(string $h) => mb_strtolower(trim($h)), $headers);

        $btHeaders = [
            'data tranzactie',
            'data valuta',
            'referinta',
            'tip tranzactie',
            'descriere',
            'debit',
            'credit',
        ];

        $matched = 0;
        foreach ($btHeaders as $expected) {
            if (in_array($expected, $normalised, true)) {
                $matched++;
            }
        }

        if ($matched >= 5) {
            return 0.95;
        }

        if ($matched >= 3) {
            return 0.7;
        }

        return 0.0;
    }

    public function setMetadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }

    public function parseRows(array $headers, iterable $rows): array
    {
        $transactions = [];
        $currency = $this->extractCurrency();

        foreach ($rows as $row) {
            $date = trim($row[self::COL_TX_DATE] ?? '');
            $description = trim($row[self::COL_DESCRIPTION] ?? '');
            $reference = trim($row[self::COL_REFERENCE] ?? '');
            $creditStr = trim($row[self::COL_CREDIT] ?? '');
            $debitStr = trim($row[self::COL_DEBIT] ?? '');
            $txType = trim($row[self::COL_TX_TYPE] ?? '');

            if (empty($date)) {
                continue;
            }

            $credit = $this->parseAmount($creditStr);

            // Only process credit (incoming) transactions
            if (bccomp($credit, '0', 2) <= 0) {
                continue;
            }

            $invoiceRef = $this->extractInvoiceReference($description);
            $explanation = implode(' | ', array_filter([
                $txType,
                $description,
                $reference ? 'Ref: ' . $reference : null,
            ]));

            $transactions[] = [
                'date' => $this->parseDate($date),
                'clientName' => $this->extractClientName($description),
                'clientCif' => $this->extractCif($description),
                'explanation' => $explanation,
                'amount' => $credit,
                'currency' => $currency,
                'awbNumber' => null,
                'bankReference' => $reference ?: null,
                'documentType' => 'transfer',
                'documentNumber' => $invoiceRef,
                'rawData' => $row,
            ];
        }

        return $transactions;
    }

    public function extractIban(iterable $rows): ?string
    {
        // BT puts IBAN in the preamble metadata, not in data rows
        $iban = $this->metadata['Numar cont'] ?? null;

        if ($iban && preg_match('/^RO\d{2}[A-Z]{4}[A-Z0-9]{16}$/i', trim($iban))) {
            return strtoupper(trim($iban));
        }

        return null;
    }

    private function extractCurrency(): string
    {
        $currency = $this->metadata['Moneda cont'] ?? '';

        return strtoupper(trim($currency)) ?: 'RON';
    }

    /**
     * Parse BT amount format. BT uses comma as decimal separator.
     * Examples: "1.234,56" or "30,00"
     */
    private function parseAmount(string $value): string
    {
        $value = str_replace([' ', "\xC2\xA0"], '', $value);

        if ($value === '' || $value === '0' || $value === '0,00' || $value === '0.00') {
            return '0.00';
        }

        // BT format: "1.234,56" — period is thousands separator, comma is decimal
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);

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

    /**
     * Parse BT date format: "DD/MM/YYYY" → "YYYY-MM-DD".
     */
    private function parseDate(string $value): string
    {
        $value = trim($value);
        if (empty($value)) {
            return date('Y-m-d');
        }

        // BT format: DD/MM/YYYY
        if (preg_match('#^(\d{1,2})[./\-](\d{1,2})[./\-](\d{4})$#', $value, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }

        if (preg_match('#^\d{4}-\d{2}-\d{2}$#', $value)) {
            return $value;
        }

        return date('Y-m-d');
    }

    /**
     * Extract invoice reference from BT description.
     * BT patterns: "F NNNNN", "fact(ura) NNN"
     */
    private function extractInvoiceReference(string $description): ?string
    {
        // BT-specific: "F NNNNN" or "F NNN" pattern (standalone F followed by number)
        if (preg_match('/\bF\s+(\d{3,})\b/', $description, $m)) {
            return $m[1];
        }

        // Generic: "Fact 24", "Factura AUT-0001", "fact. nr. 123"
        if (preg_match('/(?:^|[\s;,\-])fact(?:ura)?\.?\s*(?:nr\.?\s*)?([A-Z0-9][\w\-\/]*\d+)/i', $description, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Extract CIF from BT description.
     * BT pattern: "C.I.F.:XXXXX" or "CIF:XXXXX"
     */
    private function extractCif(string $description): ?string
    {
        // BT-specific: "C.I.F.:36330891" or "C.I.F.: 36330891"
        if (preg_match('/C\.?I\.?F\.?\s*:?\s*(\d{6,10})/i', $description, $m)) {
            return $m[1];
        }

        // Standard CUI/CIF pattern
        if (preg_match('/\b(?:CUI|CIF)\s*:?\s*(?:RO)?(\d{6,10})\b/i', $description, $m)) {
            return $m[1];
        }

        if (preg_match('/\bRO\s*(\d{6,10})\b/', $description, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Extract client/company name from BT description.
     * BT descriptions are semicolon-delimited segments. Find the first segment
     * that looks like a company name (not IBAN, SWIFT, reference, or CIF).
     */
    private function extractClientName(string $description): ?string
    {
        $segments = preg_split('/\s*;\s*/', $description);

        foreach ($segments as $segment) {
            $segment = trim($segment);

            if (empty($segment) || strlen($segment) < 3) {
                continue;
            }

            // Skip IBANs
            if (preg_match('/^[A-Z]{2}\d{2}[A-Z]{4}/i', $segment)) {
                continue;
            }

            // Skip SWIFT/BIC codes
            if (preg_match('/^[A-Z]{4}RO[A-Z0-9]{2}/i', $segment)) {
                continue;
            }

            // Skip references (pure numbers or known patterns)
            if (preg_match('/^\d+$/', $segment)) {
                continue;
            }

            // Skip CIF patterns
            if (preg_match('/C\.?I\.?F\.?\s*:/i', $segment)) {
                continue;
            }

            // Skip segments starting with known keywords
            if (preg_match('/^(Ref|Nr|Data|Curs|F\s+\d)/i', $segment)) {
                continue;
            }

            // This looks like a company name
            return $segment;
        }

        return null;
    }
}
