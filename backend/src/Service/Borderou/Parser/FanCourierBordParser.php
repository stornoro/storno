<?php

namespace App\Service\Borderou\Parser;

class FanCourierBordParser implements BorderouParserInterface
{
    private const KNOWN_HEADERS = ['AWB', 'Destinatar', 'Localitate', 'Ramburs', 'Continut'];
    private const ALT_HEADERS = ['Nr. AWB', 'Nume destinatar', 'Oras', 'Suma ramburs', 'Continut colet'];

    public function getProvider(): string
    {
        return 'fan_courier';
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
        $normalised = array_map(fn (string $h) => mb_strtolower(trim($h)), $headers);

        $matchCount = 0;
        foreach (self::KNOWN_HEADERS as $known) {
            if (in_array(mb_strtolower($known), $normalised, true)) {
                $matchCount++;
            }
        }
        if ($matchCount >= 3) {
            return 0.9;
        }

        $matchCount = 0;
        foreach (self::ALT_HEADERS as $alt) {
            if (in_array(mb_strtolower($alt), $normalised, true)) {
                $matchCount++;
            }
        }
        if ($matchCount >= 3) {
            return 0.85;
        }

        // Check for partial match (at least AWB + some amount column)
        $hasAwb = in_array('awb', $normalised, true) || in_array('nr. awb', $normalised, true);
        $hasAmount = in_array('ramburs', $normalised, true) || in_array('suma ramburs', $normalised, true) || in_array('cod', $normalised, true);

        if ($hasAwb && $hasAmount) {
            return 0.7;
        }

        return 0.0;
    }

    public function parseRows(array $headers, iterable $rows): array
    {
        $headerMap = $this->buildHeaderMap($headers);
        $transactions = [];

        foreach ($rows as $row) {
            $awb = $this->getField($row, $headerMap, 'awb');
            $client = $this->getField($row, $headerMap, 'client');
            $city = $this->getField($row, $headerMap, 'city');
            $amount = $this->getField($row, $headerMap, 'amount');
            $date = $this->getField($row, $headerMap, 'date');
            $content = $this->getField($row, $headerMap, 'content');

            if (empty($awb) && empty($amount)) {
                continue;
            }

            $parsedAmount = $this->parseAmount($amount);
            if (bccomp($parsedAmount, '0', 2) <= 0) {
                continue;
            }

            $explanation = implode(' | ', array_filter([
                $awb ? 'AWB: ' . $awb : null,
                $client,
                $city,
                $content,
            ]));

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

        $awbKeys = ['awb', 'nr. awb', 'nr.awb', 'numar awb'];
        $clientKeys = ['destinatar', 'nume destinatar', 'client', 'beneficiar'];
        $cityKeys = ['localitate', 'oras', 'loc.destinatar'];
        $amountKeys = ['ramburs', 'suma ramburs', 'cod', 'valoare ramburs', 'suma cod'];
        $dateKeys = ['data', 'data ridicare', 'data livrare', 'data borderou'];
        $contentKeys = ['continut', 'continut colet', 'observatii', 'descriere'];

        foreach (['awb' => $awbKeys, 'client' => $clientKeys, 'city' => $cityKeys, 'amount' => $amountKeys, 'date' => $dateKeys, 'content' => $contentKeys] as $key => $candidates) {
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
        $value = trim($value);
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

        // Try dd.mm.yyyy or dd/mm/yyyy
        if (preg_match('#^(\d{1,2})[./\-](\d{1,2})[./\-](\d{4})$#', $value, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }

        // Try yyyy-mm-dd
        if (preg_match('#^\d{4}-\d{2}-\d{2}$#', $value)) {
            return $value;
        }

        // Try Excel serial date (numeric)
        if (is_numeric($value) && (int) $value > 40000 && (int) $value < 60000) {
            $date = \DateTime::createFromFormat('U', (string) (((int) $value - 25569) * 86400));
            if ($date) {
                return $date->format('Y-m-d');
            }
        }

        return date('Y-m-d');
    }
}
