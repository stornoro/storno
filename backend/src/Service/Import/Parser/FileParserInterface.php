<?php

namespace App\Service\Import\Parser;

interface FileParserInterface
{
    /**
     * Check if this parser supports the given file format.
     */
    public function supports(string $fileFormat): bool;

    /**
     * Parse all rows from the file.
     *
     * @return \Generator<int, array<string, string>> yields row arrays keyed by column header
     */
    public function parse(string $filePath): \Generator;

    /**
     * Get preview data (first N rows) and detect column headers.
     *
     * @return array{headers: string[], rows: array<int, array<string, string>>}
     */
    public function preview(string $filePath, int $maxRows = 20): array;

    /**
     * Count total rows (excluding header).
     */
    public function countRows(string $filePath): int;
}
