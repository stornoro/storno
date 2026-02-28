<?php

namespace App\Service\Borderou\Parser;

class GenericBordParser implements BorderouParserInterface
{
    public function getProvider(): string
    {
        return 'generic';
    }

    public function getSourceType(): string
    {
        return 'borderou';
    }

    public function getSupportedFormats(): array
    {
        return ['csv', 'xlsx', 'xls'];
    }

    public function detectConfidence(array $headers): float
    {
        // Low confidence — used as fallback for courier bordereaux
        $normalised = array_map(fn (string $h) => mb_strtolower(trim($h)), $headers);

        $hasAwb = false;
        $hasAmount = false;

        foreach ($normalised as $h) {
            if (str_contains($h, 'awb') || str_contains($h, 'cod')) {
                $hasAwb = true;
            }
            if (str_contains($h, 'suma') || str_contains($h, 'amount') || str_contains($h, 'valoare') || str_contains($h, 'ramburs')) {
                $hasAmount = true;
            }
        }

        if ($hasAwb && $hasAmount) {
            return 0.3;
        }

        return 0.1;
    }

    public function parseRows(array $headers, iterable $rows): array
    {
        $headerMap = $this->buildHeaderMap($headers);
        $transactions = [];

        foreach ($rows as $row) {
            $amount = $this->getField($row, $headerMap, 'amount');
            $parsedAmount = $this->parseAmount($amount);

            if (bccomp($parsedAmount, '0', 2) <= 0) {
                continue;
            }

            $awb = $this->getField($row, $headerMap, 'awb');
            $client = $this->getField($row, $headerMap, 'client');
            $date = $this->getField($row, $headerMap, 'date');
            $explanation = $this->getField($row, $headerMap, 'explanation');

            if (empty($explanation)) {
                $explanation = implode(' | ', array_filter([
                    $awb ? 'AWB: ' . $awb : null,
                    $client,
                ]));
            }

            $transactions[] = [
                'date' => $this->parseDate($date),
                'clientName' => $client ?: null,
                'clientCif' => null,
                'explanation' => $explanation,
                'amount' => $parsedAmount,
                'currency' => 'RON',
                'awbNumber' => $awb ?: null,
                'bankReference' => null,
                'documentType' => 'ramburs',
                'documentNumber' => null,
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
            'awb' => ['awb', 'nr. awb', 'nr.awb', 'numar awb', 'cod awb'],
            'client' => ['client', 'destinatar', 'beneficiar', 'nume', 'name'],
            'amount' => ['suma', 'amount', 'valoare', 'ramburs', 'cod', 'suma ramburs', 'total'],
            'date' => ['data', 'date', 'data livrare', 'data ridicare'],
            'explanation' => ['explicatie', 'explicare', 'detalii', 'observatii', 'description'],
        ];

        foreach ($mappings as $key => $candidates) {
            foreach ($candidates as $candidate) {
                $idx = array_search($candidate, $normalised, true);
                if ($idx !== false) {
                    $map[$key] = $headers[$idx];
                    break;
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
        $value = str_replace(',', '.', $value);
        $value = preg_replace('/[^0-9.]/', '', $value);

        if ($value === '' || !is_numeric($value)) {
            return '0.00';
        }

        return number_format((float) $value, 2, '.', '');
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
}
