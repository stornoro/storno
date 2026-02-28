<?php

namespace App\Service\Import\Parser;

use PhpOffice\PhpSpreadsheet\IOFactory;

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
        $headers = [];
        $rowIndex = 0;

        foreach ($sheet->getRowIterator() as $row) {
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            $cells = [];
            foreach ($cellIterator as $cell) {
                $cells[] = trim((string) $cell->getValue());
            }

            if ($rowIndex === 0) {
                $headers = $cells;
                $rowIndex++;
                continue;
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
        $headers = [];
        $rows = [];
        $rowIndex = 0;

        foreach ($sheet->getRowIterator() as $row) {
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            $cells = [];
            foreach ($cellIterator as $cell) {
                $cells[] = trim((string) $cell->getValue());
            }

            if ($rowIndex === 0) {
                $headers = array_filter($cells, fn($h) => $h !== '');
                $rowIndex++;
                continue;
            }

            if (implode('', $cells) === '') {
                $rowIndex++;
                continue;
            }

            $mapped = [];
            foreach ($headers as $i => $header) {
                $mapped[$header] = $cells[$i] ?? '';
            }
            $rows[] = $mapped;

            if (count($rows) >= $maxRows) {
                break;
            }
            $rowIndex++;
        }

        $spreadsheet->disconnectWorksheets();

        return ['headers' => array_values($headers), 'rows' => $rows];
    }

    public function countRows(string $filePath): int
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $count = 0;
        $rowIndex = 0;

        foreach ($sheet->getRowIterator() as $row) {
            if ($rowIndex === 0) {
                $rowIndex++;
                continue; // skip header
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
}
