<?php

namespace App\Service\Borderou\Parser;

interface BorderouParserInterface
{
    /**
     * Provider key: 'fan_courier', 'gls', 'generic', 'generic_bank', etc.
     */
    public function getProvider(): string;

    /**
     * Source type: 'borderou' or 'bank_statement'.
     */
    public function getSourceType(): string;

    /**
     * File formats this parser supports.
     *
     * @return string[]
     */
    public function getSupportedFormats(): array;

    /**
     * Detect if file matches this parser's expected format based on headers.
     * Returns 0.0 to 1.0 confidence.
     *
     * @param string[] $headers
     */
    public function detectConfidence(array $headers): float;

    /**
     * Parse rows into standardized transaction format.
     *
     * @param string[] $headers
     * @param iterable<array<string, string>> $rows
     * @return array<int, array{
     *     date: string,
     *     clientName: ?string,
     *     clientCif: ?string,
     *     explanation: string,
     *     amount: string,
     *     currency: string,
     *     awbNumber: ?string,
     *     bankReference: ?string,
     *     documentType: ?string,
     *     documentNumber: ?string,
     *     rawData: array
     * }>
     */
    public function parseRows(array $headers, iterable $rows): array;
}
