<?php

namespace App\Service\Borderou\Pdf;

interface PdfStatementParserInterface
{
    /**
     * Provider key, matching BorderouImportService::getProviders() ('bt', 'brd', 'ing', ...).
     */
    public function getBankKey(): string;

    public function getBankLabel(): string;

    /**
     * How confident the parser is that the first page belongs to its bank.
     * 0..100; the dispatcher needs at least PdfStatementDispatcher::THRESHOLD.
     *
     * @param string[] $page1Words every word on page 1, in reading order
     */
    public function score(array $page1Words): int;

    /**
     * @param PdfPage[] $pages
     * @return PdfStatement[] one per account/currency found in the file
     */
    public function parse(array $pages): array;
}
