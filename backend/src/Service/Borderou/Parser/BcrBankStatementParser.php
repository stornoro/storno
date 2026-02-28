<?php

namespace App\Service\Borderou\Parser;

class BcrBankStatementParser implements BorderouParserInterface
{
    // BCR CSV column headers
    private const COL_DATE = 'Data finalizarii tranzactiei';
    private const COL_DESCRIPTION = 'Tranzactii finalizate (detalii)';
    private const COL_REFERENCE = 'Referinta Oper. Document';
    private const COL_DEBIT = 'Debit (suma)';
    private const COL_CREDIT = 'Credit (suma)';
    private const COL_CURRENCY = 'Valuta';
    private const COL_IBAN = 'Contul pentru care s-a generat extrasul';
    private const COL_ACCOUNT_HOLDER = 'Titular cont';

    public function getProvider(): string
    {
        return 'bcr';
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

        $bcrIndicators = 0;
        $bcrHeaders = [
            'data finalizarii tranzactiei',
            'tranzactii finalizate (detalii)',
            'referinta oper. document',
            'debit (suma)',
            'credit (suma)',
            'contul pentru care s-a generat extrasul',
            'titular cont',
            'sold contabil initial',
        ];

        foreach ($bcrHeaders as $expected) {
            if (in_array($expected, $normalised, true)) {
                $bcrIndicators++;
            }
        }

        if ($bcrIndicators >= 5) {
            return 0.95;
        }

        if ($bcrIndicators >= 3) {
            return 0.7;
        }

        return 0.0;
    }

    public function parseRows(array $headers, iterable $rows): array
    {
        $transactions = [];

        foreach ($rows as $row) {
            $date = trim($row[self::COL_DATE] ?? '');
            $description = trim($row[self::COL_DESCRIPTION] ?? '');
            $reference = trim($row[self::COL_REFERENCE] ?? '');
            $creditStr = trim($row[self::COL_CREDIT] ?? '');
            $debitStr = trim($row[self::COL_DEBIT] ?? '');
            $currency = trim($row[self::COL_CURRENCY] ?? '') ?: 'RON';

            // Skip summary/empty rows (last row in BCR CSV is a summary with no date)
            if (empty($date)) {
                continue;
            }

            $credit = $this->parseAmount($creditStr);
            $debit = $this->parseAmount($debitStr);

            // Only process credit (incoming) transactions with positive amounts
            if (bccomp($credit, '0', 2) <= 0) {
                continue;
            }

            // Skip bank fee refunds (negative debit = refund of a previous charge)
            if (str_starts_with(mb_strtolower($description), 'refuz')) {
                continue;
            }

            $invoiceRef = $this->extractInvoiceReference($description);
            $explanation = implode(' | ', array_filter([$description, $reference ? 'Ref: ' . $reference : null]));

            $transactions[] = [
                'date' => $this->parseDate($date),
                'clientName' => $this->extractClientName($description),
                'clientCif' => $this->extractCif($description),
                'explanation' => $explanation,
                'amount' => $credit,
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

    /**
     * Extract IBAN from the first data row of the CSV.
     */
    public function extractIban(iterable $rows): ?string
    {
        foreach ($rows as $row) {
            $iban = trim($row[self::COL_IBAN] ?? '');
            if (!empty($iban) && preg_match('/^RO\d{2}[A-Z]{4}\d{16}$/i', $iban)) {
                return strtoupper($iban);
            }
        }

        return null;
    }

    /**
     * Parse amount in Romanian format: "4,113.20" or "30.00" or "0.00".
     * BCR uses comma as thousands separator and period as decimal separator.
     */
    private function parseAmount(string $value): string
    {
        $value = str_replace([' ', "\xC2\xA0"], '', $value);

        if ($value === '' || $value === '0' || $value === '0.00') {
            return '0.00';
        }

        // BCR format: "4,113.20" — comma is thousands separator, period is decimal
        // Remove thousands separator (comma)
        $value = str_replace(',', '', $value);

        // Clean any remaining non-numeric chars except period and minus
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
     * Parse BCR date format: "02.12.2025" → "2025-12-02".
     */
    private function parseDate(string $value): string
    {
        $value = trim($value);
        if (empty($value)) {
            return date('Y-m-d');
        }

        // BCR format: DD.MM.YYYY
        if (preg_match('#^(\d{1,2})[./\-](\d{1,2})[./\-](\d{4})$#', $value, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }

        if (preg_match('#^\d{4}-\d{2}-\d{2}$#', $value)) {
            return $value;
        }

        return date('Y-m-d');
    }

    /**
     * Extract invoice reference from BCR description.
     * BCR patterns: "/ROC/fact 20", "Fact 24", "Factura AUT-0001", "fact. nr. 123"
     */
    private function extractInvoiceReference(string $description): ?string
    {
        // BCR-specific: "/ROC/fact 20" pattern
        if (preg_match('#/ROC/fact(?:ura)?\s*\.?\s*(?:nr\.?\s*)?([A-Z0-9][\w\-/]*\d+|\d+)#i', $description, $m)) {
            return $m[1];
        }

        // Generic: "Fact 24", "Factura AUT-0001", "fact. nr. 123"
        if (preg_match('/(?:^|[\s;,\-])fact(?:ura)?\.?\s*(?:nr\.?\s*)?([A-Z0-9][\w\-\/]*\d+)/i', $description, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Extract CIF from BCR description.
     * BCR pattern: "CODFISC 36330891" or "CODFISC 0" (0 = unknown)
     */
    private function extractCif(string $description): ?string
    {
        // BCR uses "CODFISC XXXXX"
        if (preg_match('/CODFISC\s+(\d{6,10})/i', $description, $m)) {
            return $m[1];
        }

        // Standard CUI/CIF pattern
        if (preg_match('/\b(?:CUI|CIF|C\.?U\.?I\.?)\s*:?\s*(?:RO)?(\d{6,10})\b/i', $description, $m)) {
            return $m[1];
        }

        if (preg_match('/\bRO\s*(\d{6,10})\b/', $description, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Extract client/company name from BCR description.
     * BCR pattern: "Platitor: COMPANY NAME; IBAN; CODFISC XXX"
     */
    private function extractClientName(string $description): ?string
    {
        // BCR incoming payment: "Platitor: COMPANY NAME; IBAN"
        if (preg_match('/Platitor:\s*(.+?)\s*;/i', $description, $m)) {
            $name = trim($m[1]);
            if (strlen($name) > 2 && !preg_match('/^(RO|CODFISC|IBAN)/i', $name)) {
                return $name;
            }
        }

        // Fallback: generic patterns
        if (preg_match('/(?:de la|beneficiar|ordonator)\s*:?\s*(.+?)(?:\s*(?:CUI|CIF|IBAN|CODFISC|;|$))/i', $description, $m)) {
            $name = trim($m[1]);
            if (strlen($name) > 3) {
                return $name;
            }
        }

        return null;
    }
}
