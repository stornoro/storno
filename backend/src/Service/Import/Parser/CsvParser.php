<?php

namespace App\Service\Import\Parser;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CsvParser implements FileParserInterface
{
    public function supports(string $fileFormat): bool
    {
        return in_array($fileFormat, ['csv', 'xlsx'], true);
    }

    public function parse(string $filePath): \Generator
    {
        if ($this->isXlsx($filePath)) {
            yield from $this->parseXlsx($filePath);

            return;
        }

        yield from $this->parseCsv($filePath);
    }

    public function preview(string $filePath, int $maxRows = 20): array
    {
        if ($this->isXlsx($filePath)) {
            return $this->previewXlsx($filePath, $maxRows);
        }

        return $this->previewCsv($filePath, $maxRows);
    }

    public function countRows(string $filePath): int
    {
        if ($this->isXlsx($filePath)) {
            return $this->countRowsXlsx($filePath);
        }

        return $this->countRowsCsv($filePath);
    }

    // =========================================================================
    // CSV — native fgetcsv (streaming, constant memory)
    // =========================================================================

    private function parseCsv(string $filePath): \Generator
    {
        $handle = $this->openCsv($filePath);
        $delimiter = $this->detectDelimiter($filePath);
        $headerInfo = $this->findCsvHeaderRow($handle, $delimiter);
        $headers = $headerInfo['headers'];

        while (($cells = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($this->isEmptyRow($cells)) {
                continue;
            }

            $mapped = [];
            foreach ($headers as $i => $header) {
                if ($header !== '') {
                    $mapped[$header] = trim($cells[$i] ?? '');
                }
            }

            yield $mapped;
        }

        fclose($handle);
    }

    private function previewCsv(string $filePath, int $maxRows = 20): array
    {
        $handle = $this->openCsv($filePath);
        $delimiter = $this->detectDelimiter($filePath);
        $headerInfo = $this->findCsvHeaderRow($handle, $delimiter);
        $headers = $headerInfo['headers'];
        $metadata = $headerInfo['metadata'];
        $filteredHeaders = array_filter($headers, fn ($h) => $h !== '');
        $rows = [];

        while (($cells = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($this->isEmptyRow($cells)) {
                continue;
            }

            $mapped = [];
            foreach ($filteredHeaders as $i => $header) {
                $mapped[$header] = trim($cells[$i] ?? '');
            }
            $rows[] = $mapped;

            if (count($rows) >= $maxRows) {
                break;
            }
        }

        fclose($handle);

        return [
            'headers' => array_values($filteredHeaders),
            'rows' => $rows,
            'metadata' => $metadata,
        ];
    }

    private function countRowsCsv(string $filePath): int
    {
        $handle = $this->openCsv($filePath);
        $delimiter = $this->detectDelimiter($filePath);
        $this->findCsvHeaderRow($handle, $delimiter);
        $count = 0;

        while (($cells = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (!$this->isEmptyRow($cells)) {
                $count++;
            }
        }

        fclose($handle);

        return $count;
    }

    /**
     * Open a CSV file, skipping the UTF-8 BOM if present.
     *
     * @return resource
     */
    private function openCsv(string $filePath)
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Cannot open file: %s', $filePath));
        }

        // Skip UTF-8 BOM
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        return $handle;
    }

    /**
     * Detect CSV delimiter by sampling the first few lines.
     */
    private function detectDelimiter(string $filePath): string
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return ',';
        }

        // Skip BOM
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $sample = '';
        for ($i = 0; $i < 5; $i++) {
            $line = fgets($handle);
            if ($line === false) {
                break;
            }
            $sample .= $line;
        }
        fclose($handle);

        // Count occurrences of common delimiters
        $delimiters = [',' => 0, ';' => 0, "\t" => 0, '|' => 0];
        foreach (array_keys($delimiters) as $d) {
            $delimiters[$d] = substr_count($sample, $d);
        }

        arsort($delimiters);

        return array_key_first($delimiters) ?: ',';
    }

    /**
     * Find the header row in a CSV stream.
     * If the first row with >= 3 non-empty cells is found within the first 30 rows, use it.
     * Rows before it are treated as metadata preamble.
     *
     * @param resource $handle
     * @return array{headers: string[], metadata: array<string, string>}
     */
    private function findCsvHeaderRow($handle, string $delimiter): array
    {
        $preambleRows = [];
        $rowIndex = 0;

        while ($rowIndex <= 30 && ($cells = fgetcsv($handle, 0, $delimiter)) !== false) {
            $trimmed = array_map('trim', $cells);
            $nonEmpty = count(array_filter($trimmed, fn ($c) => $c !== ''));

            if ($nonEmpty >= 3) {
                return [
                    'headers' => $trimmed,
                    'metadata' => $this->parseMetadata($preambleRows),
                ];
            }

            $preambleRows[] = $trimmed;
            $rowIndex++;
        }

        // Fallback: use first row
        return [
            'headers' => $preambleRows[0] ?? [],
            'metadata' => [],
        ];
    }

    /**
     * @param string[] $cells
     */
    private function isEmptyRow(array $cells): bool
    {
        return implode('', array_map('trim', $cells)) === '';
    }

    // =========================================================================
    // XLSX — PhpSpreadsheet (needs full load, used only for .xlsx)
    // =========================================================================

    private function isXlsx(string $filePath): bool
    {
        return strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'xlsx';
    }

    private function parseXlsx(string $filePath): \Generator
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $headerInfo = $this->findHeaderRow($sheet);
        $headerRowIndex = $headerInfo['headerRowIndex'];
        $headers = $headerInfo['headers'];
        $rowIndex = 0;

        foreach ($sheet->getRowIterator() as $row) {
            if ($rowIndex <= $headerRowIndex) {
                $rowIndex++;
                continue;
            }

            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            $cells = [];
            foreach ($cellIterator as $cell) {
                $cells[] = trim((string) $cell->getValue());
            }

            if (implode('', $cells) === '') {
                $rowIndex++;
                continue;
            }

            $mapped = [];
            foreach ($headers as $i => $header) {
                if ($header !== '') {
                    $mapped[$header] = $cells[$i] ?? '';
                }
            }

            yield $mapped;
            $rowIndex++;
        }

        $spreadsheet->disconnectWorksheets();
    }

    private function previewXlsx(string $filePath, int $maxRows = 20): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $headerInfo = $this->findHeaderRow($sheet);
        $headerRowIndex = $headerInfo['headerRowIndex'];
        $headers = $headerInfo['headers'];
        $metadata = $headerInfo['metadata'];
        $filteredHeaders = array_filter($headers, fn ($h) => $h !== '');
        $rows = [];
        $rowIndex = 0;

        foreach ($sheet->getRowIterator() as $row) {
            if ($rowIndex <= $headerRowIndex) {
                $rowIndex++;
                continue;
            }

            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            $cells = [];
            foreach ($cellIterator as $cell) {
                $cells[] = trim((string) $cell->getValue());
            }

            if (implode('', $cells) === '') {
                $rowIndex++;
                continue;
            }

            $mapped = [];
            foreach ($filteredHeaders as $i => $header) {
                $mapped[$header] = $cells[$i] ?? '';
            }
            $rows[] = $mapped;

            if (count($rows) >= $maxRows) {
                break;
            }
            $rowIndex++;
        }

        $spreadsheet->disconnectWorksheets();

        return [
            'headers' => array_values($filteredHeaders),
            'rows' => $rows,
            'metadata' => $metadata,
        ];
    }

    private function countRowsXlsx(string $filePath): int
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $headerInfo = $this->findHeaderRow($sheet);
        $headerRowIndex = $headerInfo['headerRowIndex'];
        $count = 0;
        $rowIndex = 0;

        foreach ($sheet->getRowIterator() as $row) {
            if ($rowIndex <= $headerRowIndex) {
                $rowIndex++;
                continue;
            }
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            $cells = [];
            foreach ($cellIterator as $cell) {
                $cells[] = trim((string) $cell->getValue());
            }
            if (implode('', $cells) !== '') {
                $count++;
            }
            $rowIndex++;
        }

        $spreadsheet->disconnectWorksheets();

        return $count;
    }

    // =========================================================================
    // Shared helpers
    // =========================================================================

    /**
     * Find the header row in an XLSX sheet.
     *
     * @return array{headerRowIndex: int, headers: string[], metadata: array<string, string>}
     */
    private function findHeaderRow(Worksheet $sheet): array
    {
        $preambleRows = [];
        $rowIndex = 0;

        foreach ($sheet->getRowIterator() as $row) {
            if ($rowIndex > 30) {
                break;
            }

            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            $cells = [];
            foreach ($cellIterator as $cell) {
                $cells[] = trim((string) $cell->getValue());
            }

            $nonEmpty = count(array_filter($cells, fn ($c) => $c !== ''));

            if ($nonEmpty >= 3) {
                return [
                    'headerRowIndex' => $rowIndex,
                    'headers' => $cells,
                    'metadata' => $this->parseMetadata($preambleRows),
                ];
            }

            $preambleRows[] = $cells;
            $rowIndex++;
        }

        return [
            'headerRowIndex' => 0,
            'headers' => $preambleRows[0] ?? [],
            'metadata' => [],
        ];
    }

    /**
     * Convert preamble rows to key-value pairs.
     *
     * @param array<int, string[]> $preambleRows
     * @return array<string, string>
     */
    private function parseMetadata(array $preambleRows): array
    {
        $metadata = [];

        foreach ($preambleRows as $cells) {
            $nonEmpty = array_filter($cells, fn ($c) => $c !== '');

            if (count($nonEmpty) === 2) {
                $values = array_values($nonEmpty);
                $key = rtrim($values[0], ':');
                $metadata[$key] = $values[1];
            } elseif (count($nonEmpty) === 1) {
                $value = array_values($nonEmpty)[0];
                if (str_contains($value, ':')) {
                    $parts = explode(':', $value, 2);
                    $metadata[trim($parts[0])] = trim($parts[1]);
                }
            }
        }

        return $metadata;
    }
}
