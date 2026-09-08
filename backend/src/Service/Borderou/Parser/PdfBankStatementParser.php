<?php

namespace App\Service\Borderou\Parser;

use App\Service\Import\Parser\PdfStatementFileParser as Pdf;

/**
 * Consumes the normalised rows produced by PdfStatementFileParser (any bank)
 * and turns incoming (credit) transactions into borderou transactions.
 */
class PdfBankStatementParser implements BorderouParserInterface
{
    public const PROVIDER = 'pdf_bank';

    /** @var array<string, mixed> */
    private array $metadata = [];

    public function getProvider(): string
    {
        return self::PROVIDER;
    }

    public function getSourceType(): string
    {
        return 'bank_statement';
    }

    public function getSupportedFormats(): array
    {
        return ['pdf'];
    }

    public function detectConfidence(array $headers): float
    {
        return in_array(Pdf::HEADER_MARKER, $headers, true) ? 1.0 : 0.0;
    }

    public function setMetadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function parseRows(array $headers, iterable $rows): array
    {
        $transactions = [];
        foreach ($rows as $row) {
            $credit = trim((string) ($row[Pdf::COL_CREDIT] ?? '0'));
            if ($credit === '' || bccomp($credit, '0', 2) <= 0) {
                continue; // only money coming in is matched against invoices
            }
            $description = trim((string) ($row[Pdf::COL_DESCRIPTION] ?? ''));
            $reference = trim((string) ($row[Pdf::COL_REFERENCE] ?? ''));
            $counterparty = trim((string) ($row[Pdf::COL_COUNTERPARTY] ?? ''));

            $transactions[] = [
                'date' => $row[Pdf::COL_DATE],
                'clientName' => $counterparty !== '' ? $counterparty : $this->extractClientName($description),
                'clientCif' => $this->extractCif($description),
                'explanation' => implode(' | ', array_filter([
                    $description,
                    $reference !== '' ? 'Ref: ' . $reference : null,
                ])),
                'amount' => number_format((float) $credit, 2, '.', ''),
                'currency' => strtoupper(trim((string) ($row[Pdf::COL_CURRENCY] ?? ''))) ?: ($this->metadata['Moneda cont'] ?? 'RON'),
                'awbNumber' => null,
                'bankReference' => $reference !== '' ? $reference : null,
                'documentType' => 'transfer',
                'documentNumber' => $this->extractInvoiceReference($description),
                'rawData' => $row,
            ];
        }

        return $transactions;
    }

    public function extractIban(iterable $rows): ?string
    {
        $iban = $this->metadata['Numar cont'] ?? null;
        if ($iban && preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/i', trim((string) $iban))) {
            return strtoupper(trim((string) $iban));
        }
        foreach ($rows as $row) {
            $v = trim((string) ($row[Pdf::COL_IBAN] ?? ''));
            if ($v !== '') {
                return strtoupper($v);
            }
            break;
        }

        return null;
    }

    private function extractInvoiceReference(string $description): ?string
    {
        if (preg_match('/\b(?:fact(?:ura)?|fct|inv(?:oice)?|f)\.?\s*(?:nr\.?\s*)?([A-Z]{0,6}[-\/ ]?\d{1,10})\b/iu', $description, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function extractCif(string $description): ?string
    {
        if (preg_match('/\b(?:CUI|CIF|C\.U\.I\.|C\.I\.F\.)[:\s]*(?:RO)?\s*(\d{2,10})\b/i', $description, $m)) {
            return $m[1];
        }

        return null;
    }

    private function extractClientName(string $description): ?string
    {
        // "PLATITOR: X SRL", "de la X SRL", "ORDONATOR X S.R.L."
        if (preg_match('/(?:platitor|ordonator|de la|from|beneficiar)[:\s]+([A-Z][A-Z0-9 .&\-]{1,80}?(?:S\.?R\.?L\.?|S\.?A\.?|P\.?F\.?A\.?|SRL-D|I\.?I\.?|S\.?C\.?S\.?)\b)/iu', $description, $m)) {
            return trim($m[1]);
        }

        // Otherwise: the words right before a legal-form token. Bank descriptions
        // usually print counterparty names in upper case, so prefer the run of
        // upper-case words; fall back to the last three words.
        $words = preg_split('/\s+/', trim($description)) ?: [];
        foreach ($words as $i => $word) {
            if (!preg_match('/^(S\.?R\.?L\.?|S\.?A\.?|P\.?F\.?A\.?|SRL-D|I\.?I\.?|S\.?C\.?S\.?),?$/i', $word)) {
                continue;
            }
            $name = [];
            for ($j = $i - 1; $j >= 0 && count($name) < 6; $j--) {
                $w = $words[$j];
                if (preg_match('/^[0-9.,\/-]+$/', $w) || !preg_match('/^[A-Z0-9&.\-]+$/u', $w)) {
                    break;
                }
                array_unshift($name, $w);
            }
            if ($name === []) {
                $name = array_slice($words, max(0, $i - 3), min(3, $i));
                $name = array_values(array_filter($name, static fn (string $w) => !preg_match('/^[0-9.,\/-]+$/', $w)));
            }
            if ($name !== []) {
                return implode(' ', $name) . ' ' . rtrim($word, ',');
            }
        }

        return null;
    }
}
