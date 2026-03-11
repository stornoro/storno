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

            // Skip completely empty rows
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

    public function preview(string $filePath, int $maxRows = 20): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $headerInfo = $this->findHeaderRow($sheet);
        $headerRowIndex = $headerInfo['headerRowIndex'];
        $headers = $headerInfo['headers'];
        $metadata = $headerInfo['metadata'];
        $filteredHeaders = array_filter($headers, fn($h) => $h !== '');
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

    public function countRows(string $filePath): int
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

    /**
     * Find the header row in the sheet. If row 0 has >= 3 non-empty cells,
     * use it (backward-compatible). Otherwise scan forward up to 30 rows.
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

            $nonEmpty = count(array_filter($cells, fn($c) => $c !== ''));

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

        // Fallback: use row 0
        return [
            'headerRowIndex' => 0,
            'headers' => $preambleRows[0] ?? [],
            'metadata' => [],
        ];
    }

    /**
     * Convert preamble rows to key-value pairs.
     * Rows with exactly 2 non-empty cells where the first ends with ":" are treated as key-value.
     *
     * @param array<int, string[]> $preambleRows
     * @return array<string, string>
     */
    private function parseMetadata(array $preambleRows): array
    {
        $metadata = [];

        foreach ($preambleRows as $cells) {
            $nonEmpty = array_filter($cells, fn($c) => $c !== '');

            if (count($nonEmpty) === 2) {
                $values = array_values($nonEmpty);
                $key = rtrim($values[0], ':');
                $metadata[$key] = $values[1];
            } elseif (count($nonEmpty) === 1) {
                // Single-cell preamble rows (e.g., "Lista de tranzactii")
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
