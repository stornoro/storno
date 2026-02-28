<?php

namespace App\Service\Balance;

use Smalot\PdfParser\Parser;

class TrialBalancePdfParser
{
    /**
     * Parse a Romanian trial balance (Balanta de verificare) PDF.
     *
     * Supports common accounting software exports: SAGA C, Ciel, FGO, etc.
     */
    public function parse(string $pdfContent): TrialBalanceParsedResult
    {
        $parser = new Parser();
        $pdf = $parser->parseContent($pdfContent);

        $result = new TrialBalanceParsedResult();
        $allText = '';

        foreach ($pdf->getPages() as $page) {
            $allText .= $page->getText() . "\n";
        }

        $this->detectPeriod($allText, $result);
        $this->detectSourceSoftware($allText, $result);
        $this->detectCompanyCui($allText, $result);
        $this->parseRows($allText, $result);

        return $result;
    }

    /**
     * Parse from raw text (for testing).
     */
    public function parseFromText(string $text): TrialBalanceParsedResult
    {
        $result = new TrialBalanceParsedResult();
        $this->detectPeriod($text, $result);
        $this->detectSourceSoftware($text, $result);
        $this->detectCompanyCui($text, $result);
        $this->parseRows($text, $result);

        return $result;
    }

    private function detectPeriod(string $text, TrialBalanceParsedResult $result): void
    {
        // Pattern: "Perioada: DD.MM.YYYY - DD.MM.YYYY" or "Perioada: DD/MM/YYYY - DD/MM/YYYY"
        // Handles single dash, double dash, en-dash
        if (preg_match('/Perioada\s*[:.]?\s*\d{1,2}[.\/]\d{1,2}[.\/](\d{4})\s*[-–]{1,2}\s*\d{1,2}[.\/](\d{1,2})[.\/](\d{4})/iu', $text, $m)) {
            $result->year = (int) $m[3];
            $result->month = (int) $m[2];
            return;
        }

        // Pattern: "Luna: Ianuarie 2025" or "Luna: 01/2025"
        if (preg_match('/Luna\s*[:.]?\s*(\w+)\s+(\d{4})/iu', $text, $m)) {
            $result->year = (int) $m[2];
            $result->month = $this->monthNameToNumber($m[1]);
            return;
        }

        if (preg_match('/Luna\s*[:.]?\s*(\d{1,2})\s*[\/\-]\s*(\d{4})/iu', $text, $m)) {
            $result->month = (int) $m[1];
            $result->year = (int) $m[2];
            return;
        }

        // Pattern: date range "01.01.2025 - 31.01.2025" or "01.01.2023 -- 31.12.2023"
        // Handles single dash, double dash, en-dash
        if (preg_match('/\d{1,2}\.\d{1,2}\.(\d{4})\s*[-–]{1,2}\s*\d{1,2}\.(\d{1,2})\.(\d{4})/u', $text, $m)) {
            $result->year = (int) $m[3];
            $result->month = (int) $m[2];
            return;
        }

        // Pattern: concatenated dates from PDF table extraction: "01.01.202331.12.2023"
        // Some PDF parsers extract table cells without separators
        if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})(\d{2})\.(\d{2})\.(\d{4})/', $text, $m)) {
            $result->year = (int) $m[6];
            $result->month = (int) $m[5];
        }
    }

    private function detectSourceSoftware(string $text, TrialBalanceParsedResult $result): void
    {
        $patterns = [
            'SAGA' => '/\bSAGA\b/',
            'Ciel' => '/\bCiel\b/i',
            'FGO' => '/\bFGO\b/i',
            'WinMentor' => '/\bWinMentor\b/i',
            'Nexus' => '/\bNexus\b/i',
            'Charme' => '/\bCharme\b/i',
            'ASiS' => '/\bASiS\b/i',
        ];

        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $text)) {
                $result->sourceSoftware = $name;
                return;
            }
        }
    }

    private function detectCompanyCui(string $text, TrialBalanceParsedResult $result): void
    {
        // Pattern: "CUI: 12345678" or "CIF: 12345678" or "Cod fiscal: 12345678"
        // Also: "C.U.I.: 12345678", "C.I.F.: 12345678", "c.f. RO12345678"
        // May include RO prefix: "RO12345678"
        $patterns = [
            '/(?:C\.?U\.?I\.?|C\.?I\.?F\.?|c\.?f\.?|Cod\s+fiscal|Cod\s+unic)\s*[:.]?\s*(?:RO)?\s*(\d{2,10})/iu',
            '/(?:Societatea|S\.?C\.?)\s+.{3,60}?\s+(?:RO)?(\d{6,10})/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $cui = ltrim($m[1], '0');
                if (strlen($cui) >= 2 && strlen($cui) <= 10) {
                    $result->companyCui = $cui;
                    return;
                }
            }
        }
    }

    private function parseRows(string $text, TrialBalanceParsedResult $result): void
    {
        $lines = preg_split('/\r?\n/', $text);
        if (!$lines) {
            return;
        }

        // Multi-line account entries: accumulate lines until we have a complete entry.
        // An account entry starts with a line matching an account code (3-6 digits).
        // Continuation lines (wrapped account names, number-only lines) are appended.
        $currentEntry = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Skip header/total/class summary lines
            if ($this->isSkippableLine($line)) {
                // Flush pending entry before skipping
                if ($currentEntry !== null) {
                    $row = $this->parseAccountLine($currentEntry);
                    if ($row !== null) {
                        $result->rows[] = $row;
                    }
                    $currentEntry = null;
                }
                continue;
            }

            // Check if line starts with an account code (3-6 digits)
            if (preg_match('/^\s*\d{3,6}(?:\s|[A-Z])/u', $line)) {
                // Flush previous entry
                if ($currentEntry !== null) {
                    $row = $this->parseAccountLine($currentEntry);
                    if ($row !== null) {
                        $result->rows[] = $row;
                    }
                }
                $currentEntry = $line;
            } else {
                // Continuation line — append to current entry
                if ($currentEntry !== null) {
                    $currentEntry .= ' ' . $line;
                }
            }
        }

        // Flush final entry
        if ($currentEntry !== null) {
            $row = $this->parseAccountLine($currentEntry);
            if ($row !== null) {
                $result->rows[] = $row;
            }
        }
    }

    private function isSkippableLine(string $line): bool
    {
        // Skip total rows, class headers, page headers
        $skipPatterns = [
            '/^TOTAL/i',
            '/^Clasa\s/i',
            '/^Cont\b/i',
            '/^Simbol/i',
            '/^Pag\b/i',
            '/^Pagina\s/i',
            '/^Balanta\s/i',
            '/^Societatea/i',
            '/^Perioada/i',
            '/^Luna\b/i',
            '/^Sold\w*\s+initial/i',
            '/^Solduri/i',
            '/^Rulaj/i',
            '/^Rulaje/i',
            '/^Sume\s+(precedente|totale)/i',
            '/^Den\w*\s+cont/i',
            '/^Denumirea/i',
            '/^Debitoare/i',
            '/^Creditoare/i',
            '/^-{2,}/',
            '/^={3,}/',
            '/^c\.?f\.?\s/i',
            '/^r\.?c\.?\s/i',
            '/^Capital\s+social/i',
            '/^Nr\.\s*crt/i',
            // Concatenated header text from PDF table extraction
            '/^DebitoareCreditoare/i',
            '/^SolduriSume/i',
        ];

        foreach ($skipPatterns as $pattern) {
            if (preg_match($pattern, $line)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse a single account line from the trial balance.
     *
     * Expected format: account_code[space]account_name numbers...
     * OR: account_codeACCOUNT_NAME numbers... (no space between code and name)
     *
     * The 10 numbers correspond to: initialD, initialC, prevD, prevC, currD, currC, totalD, totalC, finalD, finalC
     * Some PDFs use dashes (-) to represent zero values.
     *
     * Strategy: extract ALL numbers (including dashes-as-zero) from the line,
     * then take the LAST 10 — this naturally skips any numbers that appear
     * in the account name (e.g., "CONT NR 5" or "GRUPA 3").
     *
     * @return array{accountCode: string, accountName: string, initialDebit: string, initialCredit: string, previousDebit: string, previousCredit: string, currentDebit: string, currentCredit: string, totalDebit: string, totalCredit: string, finalDebit: string, finalCredit: string}|null
     */
    private function parseAccountLine(string $line): ?array
    {
        // Match account code (3-6 digits) at the start
        // Allow no space between code and name (e.g., "1012CAPITAL SUBSCRIS VARSAT")
        if (!preg_match('/^\s*(\d{3,6})(?:\s+|(?=[A-Z]))/u', $line, $codeMatch)) {
            return null;
        }

        $accountCode = $codeMatch[1];

        // Extract all numbers from the line after the account code
        $numbers = $this->extractNumbers($line, strlen($codeMatch[0]));

        // We need at least 10 numbers for the balance columns.
        // Take the LAST 10 to skip any numbers in the account name.
        if (count($numbers) < 10) {
            return null;
        }

        // Take the last 10 numbers (column values)
        $columnValues = array_slice($numbers, -10);

        // Extract account name: text between account code and the numeric section
        $accountName = $this->extractAccountName($line, $codeMatch[0]);

        return [
            'accountCode' => $accountCode,
            'accountName' => $accountName,
            'initialDebit' => $this->formatDecimal($columnValues[0]),
            'initialCredit' => $this->formatDecimal($columnValues[1]),
            'previousDebit' => $this->formatDecimal($columnValues[2]),
            'previousCredit' => $this->formatDecimal($columnValues[3]),
            'currentDebit' => $this->formatDecimal($columnValues[4]),
            'currentCredit' => $this->formatDecimal($columnValues[5]),
            'totalDebit' => $this->formatDecimal($columnValues[6]),
            'totalCredit' => $this->formatDecimal($columnValues[7]),
            'finalDebit' => $this->formatDecimal($columnValues[8]),
            'finalCredit' => $this->formatDecimal($columnValues[9]),
        ];
    }

    /**
     * Extract numeric values from the line after the account code.
     *
     * Handles multiple number formats:
     * - Romanian: 1.234.567,89 (dots as thousands sep, comma as decimal)
     * - Space-separated: 1 101 657.93 (spaces as thousands sep, dot as decimal)
     * - Standard: 1234567.89 (no thousands sep, dot as decimal)
     * - Plain integer: 12345
     * - Standalone dash (-, –, —): treated as zero (common in Romanian trial balances)
     *
     * @return string[]
     */
    private function extractNumbers(string $line, int $offset): array
    {
        $remainder = substr($line, $offset);

        // Pre-process: replace standalone dashes (surrounded by whitespace) with 0
        // This handles the common format where "-" or "–" or "—" represents zero
        // The /u flag is required for proper UTF-8 handling of en-dash and em-dash
        $remainder = preg_replace('/(?<=\s)[-–—](?=\s|$)/u', '0', $remainder) ?? $remainder;
        // Also handle dash at the very beginning of remainder (after code match)
        $remainder = preg_replace('/^[-–—](?=\s)/u', '0', $remainder) ?? $remainder;

        $numbers = [];

        // Order matters — try most specific patterns first
        // 1. Romanian format: 1.234,56 or 1.234.567,89
        // 2. Space-separated thousands with dot decimal: 1 101 657.93
        // 3. Standard decimal: 1234.56
        // 4. Plain integer: 12345
        preg_match_all(
            '/(?<!\w)((?:\d{1,3}(?:\.\d{3})*),\d{1,2})(?!\w)'
            . '|(\d{1,3}(?:\s\d{3})+\.\d{1,2})'
            . '|(?<!\w)(\d+\.\d{1,2})(?!\w)'
            . '|(?<!\w)(\d+)(?!\w)/',
            $remainder,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            // Note: use !== '' instead of !empty() because empty('0') is true in PHP
            if (($match[1] ?? '') !== '') {
                // Romanian format: 1.234,56
                $numbers[] = $match[1];
            } elseif (($match[2] ?? '') !== '') {
                // Space-separated thousands: 1 101 657.93
                $numbers[] = $match[2];
            } elseif (($match[3] ?? '') !== '') {
                // Standard format: 1234.56
                $numbers[] = $match[3];
            } elseif (($match[4] ?? '') !== '') {
                // Plain integer (including "0")
                $numbers[] = $match[4];
            }
        }

        return $numbers;
    }

    private function extractAccountName(string $line, string $codeMatch): string
    {
        $afterCode = substr($line, strlen($codeMatch));

        // Remove all numeric content (Romanian, space-separated, standard, plain) and standalone dashes
        $nameCandidate = preg_replace('/(?:\d{1,3}(?:\.\d{3})*,\d{1,2}|\d{1,3}(?:\s\d{3})+\.\d{1,2}|\d+\.\d{1,2}|\b\d+\b)/', '', $afterCode);
        $nameCandidate = trim($nameCandidate ?? '');

        // Clean up extra spaces
        $nameCandidate = preg_replace('/\s{2,}/', ' ', $nameCandidate) ?? '';

        // Remove leading/trailing dashes and hyphens that were column zeros
        $nameCandidate = preg_replace('/\s+[-–—]\s+/', ' ', $nameCandidate) ?? '';

        return trim($nameCandidate);
    }

    /**
     * Convert a formatted number to standard decimal string.
     * "1.234.567,89" → "1234567.89" (Romanian: dot thousands, comma decimal)
     * "1 101 657.93" → "1101657.93" (Space thousands, dot decimal)
     * "0,00" → "0.00"
     * "1234.56" → "1234.56" (already standard)
     * "0" → "0.00"
     */
    private function formatDecimal(string $value): string
    {
        $value = trim($value);

        // Check if Romanian format (contains comma as decimal separator)
        if (str_contains($value, ',')) {
            // Remove thousands dots, replace decimal comma with dot
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        }

        // Remove space thousands separators (e.g. "1 101 657.93" → "1101657.93")
        $value = preg_replace('/(\d)\s+(\d)/', '$1$2', $value) ?? $value;

        // Ensure 2 decimal places
        $floatVal = (float) $value;

        return number_format($floatVal, 2, '.', '');
    }

    private function monthNameToNumber(string $name): ?int
    {
        $months = [
            'ianuarie' => 1, 'februarie' => 2, 'martie' => 3, 'aprilie' => 4,
            'mai' => 5, 'iunie' => 6, 'iulie' => 7, 'august' => 8,
            'septembrie' => 9, 'octombrie' => 10, 'noiembrie' => 11, 'decembrie' => 12,
            // Abbreviated
            'ian' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4,
            'iun' => 6, 'iul' => 7, 'aug' => 8,
            'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
        ];

        return $months[mb_strtolower($name)] ?? null;
    }
}
