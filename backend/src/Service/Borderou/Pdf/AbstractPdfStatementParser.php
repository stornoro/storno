<?php

namespace App\Service\Borderou\Pdf;

/**
 * Shared toolbox for bank-specific PDF statement parsers: line clustering,
 * table header detection, column assignment by x position, amount and date
 * parsing, IBAN/CUI extraction and balance reconciliation.
 */
abstract class AbstractPdfStatementParser implements PdfStatementParserInterface
{
    protected const IBAN_REGEX = '/\b([A-Z]{2}\d{2}[A-Z0-9]{11,30})\b/';
    protected const RO_IBAN_REGEX = '/\bRO\d{2}[A-Z]{4}[A-Z0-9]{16}\b/';

    protected LineClusterer $clusterer;

    public function __construct()
    {
        $this->clusterer = new LineClusterer();
    }

    // ------------------------------------------------------------------
    // Detection helpers
    // ------------------------------------------------------------------

    /**
     * Adds the weight of every phrase found in the page text (case- and
     * diacritics-insensitive, whitespace-normalised).
     *
     * @param string[] $page1Words
     * @param array<string, int> $weights phrase => points
     */
    protected function scoreByPhrases(array $page1Words, array $weights): int
    {
        $haystack = $this->normalise(implode(' ', $page1Words));
        $score = 0;
        foreach ($weights as $phrase => $points) {
            if (str_contains($haystack, $this->normalise($phrase))) {
                $score += $points;
            }
        }

        return min(100, $score);
    }

    /**
     * Lowercase, strip diacritics, collapse whitespace.
     */
    protected function normalise(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = strtr($text, [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
            'é' => 'e', 'è' => 'e', 'ü' => 'u', 'ö' => 'o', 'ä' => 'a',
        ]);

        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }

    // ------------------------------------------------------------------
    // Layout helpers
    // ------------------------------------------------------------------

    /**
     * @return array<int, PdfWord[]> lines of one page, top to bottom
     */
    protected function lines(PdfPage $page, float $tolerance = 2.5): array
    {
        return $this->clusterer->cluster($page->words, $tolerance);
    }

    /**
     * @param PdfWord[] $line
     */
    protected function lineText(array $line): string
    {
        return LineClusterer::text($line);
    }

    /**
     * Finds the first line containing every required label (normalised
     * substring match on the joined line text). Returns the line index or null.
     *
     * @param array<int, PdfWord[]> $lines
     * @param string[] $requiredLabels
     */
    protected function findHeaderLine(array $lines, array $requiredLabels, int $startAt = 0): ?int
    {
        $count = count($lines);
        for ($i = $startAt; $i < $count; $i++) {
            $text = $this->normalise($this->lineText($lines[$i]));
            $ok = true;
            foreach ($requiredLabels as $label) {
                if (!str_contains($text, $this->normalise($label))) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Locates the x position (left edge and centre) of each column label in a header line.
     * A label may span several words ("Data valutei"); the first word's left edge and the
     * span's centre are returned.
     *
     * @param PdfWord[] $headerLine
     * @param array<string, string|string[]> $labels column key => label (or list of alternative labels)
     * @return array<string, array{x0: float, x1: float, center: float}> only the columns that were found
     */
    protected function locateColumns(array $headerLine, array $labels): array
    {
        $found = [];
        $n = count($headerLine);
        foreach ($labels as $key => $alternatives) {
            foreach ((array) $alternatives as $label) {
                $labelWords = preg_split('/\s+/', $this->normalise($label)) ?: [];
                $len = count($labelWords);
                for ($i = 0; $i + $len <= $n; $i++) {
                    $match = true;
                    for ($j = 0; $j < $len; $j++) {
                        if ($this->normalise($headerLine[$i + $j]->text) !== $labelWords[$j]) {
                            $match = false;
                            break;
                        }
                    }
                    if ($match) {
                        $x0 = $headerLine[$i]->x0;
                        $x1 = $headerLine[$i + $len - 1]->x1;
                        $found[$key] = ['x0' => $x0, 'x1' => $x1, 'center' => ($x0 + $x1) / 2];
                        continue 3;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Turns located column positions into contiguous [left, right) x ranges by
     * splitting halfway between neighbouring columns.
     *
     * @param array<string, array{x0: float, x1: float, center: float}> $columns
     * @return array<string, array{left: float, right: float}>
     */
    protected function columnBounds(array $columns, float $pageWidth = 10000.0): array
    {
        uasort($columns, static fn (array $a, array $b) => $a['x0'] <=> $b['x0']);
        $keys = array_keys($columns);
        $bounds = [];
        $prevRight = -1e9;
        foreach ($keys as $idx => $key) {
            $current = $columns[$key];
            $next = isset($keys[$idx + 1]) ? $columns[$keys[$idx + 1]] : null;
            $left = $prevRight;
            $right = $next ? ($current['x1'] + $next['x0']) / 2 : $pageWidth;
            $bounds[$key] = ['left' => $left, 'right' => $right];
            $prevRight = $right;
        }

        return $bounds;
    }

    /**
     * Assigns each word of a line to the column whose x range contains the word's centre.
     *
     * @param PdfWord[] $line
     * @param array<string, array{left: float, right: float}> $bounds
     * @return array<string, string> column key => joined text ('' when empty)
     */
    protected function assignColumns(array $line, array $bounds): array
    {
        $cells = array_fill_keys(array_keys($bounds), []);
        foreach ($line as $word) {
            $cx = $word->centerX();
            foreach ($bounds as $key => $range) {
                if ($cx >= $range['left'] && $cx < $range['right']) {
                    $cells[$key][] = $word->text;
                    continue 2;
                }
            }
        }

        return array_map(static fn (array $parts) => trim(implode(' ', $parts)), $cells);
    }

    /**
     * Same as assignColumns() but uses right-aligned matching for amount columns:
     * a word belongs to the amount column whose right edge is closest to the word's right edge.
     *
     * @param PdfWord[] $line
     * @param array<string, array{x0: float, x1: float, center: float}> $columns
     * @param string[] $amountKeys
     */
    protected function nearestAmountColumn(PdfWord $word, array $columns, array $amountKeys, float $maxDistance = 40.0): ?string
    {
        $best = null;
        $bestDist = $maxDistance;
        foreach ($amountKeys as $key) {
            if (!isset($columns[$key])) {
                continue;
            }
            $dist = abs($columns[$key]['x1'] - $word->x1);
            $distCenter = abs($columns[$key]['center'] - $word->centerX());
            $d = min($dist, $distCenter);
            if ($d < $bestDist) {
                $bestDist = $d;
                $best = $key;
            }
        }

        return $best;
    }

    /**
     * Splits a line into cells by fixed x boundaries: a word belongs to the
     * last boundary its left edge has passed (boundaries[0] is -INF).
     *
     * @param PdfWord[] $line
     * @param float[] $boundaries ascending, first element is the left edge of column 0
     * @return string[] one string per column (count($boundaries) cells)
     */
    protected function splitByBoundaries(array $line, array $boundaries, bool $useCenter = false): array
    {
        $n = count($boundaries);
        $cells = array_fill(0, $n, []);
        foreach ($line as $word) {
            $x = $useCenter ? $word->centerX() : $word->x0;
            $idx = 0;
            for ($i = 0; $i < $n; $i++) {
                if ($x >= $boundaries[$i]) {
                    $idx = $i;
                }
            }
            $cells[$idx][] = $word->text;
        }

        return array_map(static fn (array $c) => trim(implode(' ', $c)), $cells);
    }

    /**
     * All lines of all pages, in reading order, optionally restricted to a
     * vertical band (top-left origin: $minY/$maxY are distances from the page top).
     *
     * @param PdfPage[] $pages
     * @return array<int, array{page: int, words: PdfWord[]}>
     */
    protected function allLines(array $pages, float $tolerance = 2.5, ?float $minY = null, ?float $maxY = null): array
    {
        $out = [];
        foreach ($pages as $page) {
            $words = $page->words;
            if ($minY !== null || $maxY !== null) {
                $words = array_values(array_filter($words, static function (PdfWord $w) use ($minY, $maxY): bool {
                    if ($minY !== null && $w->y0 < $minY) {
                        return false;
                    }
                    if ($maxY !== null && $w->y1 > $maxY) {
                        return false;
                    }

                    return true;
                }));
            }
            foreach ($this->clusterer->cluster($words, $tolerance) as $line) {
                $out[] = ['page' => $page->number, 'words' => $line];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Value parsing
    // ------------------------------------------------------------------

    private const MONTHS = [
        'jan' => 1, 'ian' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'mai' => 5, 'jun' => 6, 'iun' => 6,
        'jul' => 7, 'iul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'noi' => 11, 'dec' => 12,
        'january' => 1, 'ianuarie' => 1, 'february' => 2, 'februarie' => 2, 'march' => 3, 'martie' => 3, 'april' => 4,
        'aprilie' => 4, 'june' => 6, 'iunie' => 6, 'july' => 7, 'iulie' => 7, 'august' => 8, 'september' => 9,
        'septembrie' => 9, 'october' => 10, 'octombrie' => 10, 'november' => 11, 'noiembrie' => 11, 'december' => 12,
        'decembrie' => 12,
    ];

    /**
     * Strict whole-string date recognition covering the shapes banks print:
     * dd-MM-yyyy, dd.MM.yyyy, dd/MM/yyyy, dd.MM.yyyy HH:mm, dd MMM yyyy, dd-MMM-yyyy,
     * dd MMMM yyyy (English or Romanian month names), yyyy-MM-dd.
     */
    protected function parseDateStrict(?string $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        $v = trim($value);
        if (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})(?:\s+\d{1,2}:\d{2}(?::\d{2})?)?$/', $v, $m)) {
            return $this->makeDate((int) $m[3], (int) $m[2], (int) $m[1]);
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
            return $this->makeDate((int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/^(\d{1,2})[\s-]+([[:alpha:]]+)\.?[\s-]+(\d{4})$/u', $v, $m)) {
            $month = self::MONTHS[$this->normalise($m[2])] ?? null;
            if ($month) {
                return $this->makeDate((int) $m[3], $month, (int) $m[1]);
            }
        }

        return null;
    }

    private function makeDate(int $y, int $m, int $d): ?\DateTimeImmutable
    {
        if (!checkdate($m, $d, $y)) {
            return null;
        }

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $m, $d));
    }

    /**
     * English-style money ("12,345.67"): commas are thousands separators. Bare integers parse too.
     */
    protected function parseMoneyEn(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = str_replace(',', '', trim($value));
        $negative = false;
        if (preg_match('/^\((.*)\)$/', $v, $m)) {
            $negative = true;
            $v = $m[1];
        }
        if (str_ends_with($v, '-') || str_starts_with($v, '-')) {
            $negative = true;
        }
        $v = trim($v, '+- ');
        if ($v === '' || !preg_match('/^\d+(\.\d+)?$/', $v)) {
            return null;
        }
        $r = number_format((float) $v, 2, '.', '');

        return $negative ? '-' . $r : $r;
    }

    /**
     * Romanian-style money ("12.345,67"): dots are thousands separators, comma is decimal.
     */
    protected function parseMoneyRo(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim($value);
        $negative = false;
        if (preg_match('/^\((.*)\)$/', $v, $m)) {
            $negative = true;
            $v = $m[1];
        }
        if (str_ends_with($v, '-') || str_starts_with($v, '-')) {
            $negative = true;
        }
        $v = trim($v, '+- ');
        $v = str_replace(['.', ' '], '', $v);
        $v = str_replace(',', '.', $v);
        if ($v === '' || !preg_match('/^\d+(\.\d+)?$/', $v)) {
            return null;
        }
        $r = number_format((float) $v, 2, '.', '');

        return $negative ? '-' . $r : $r;
    }

    protected function bcAbs(string $v): string
    {
        return ltrim($v, '-');
    }

    /**
     * Running-balance bookkeeping shared by every parser.
     *
     * @return array{opening: string, closing: string}
     */
    protected function step(string $running, string $amount, bool $credit): array
    {
        $closing = $credit ? bcadd($running, $amount, 2) : bcsub($running, $amount, 2);

        return ['opening' => $running, 'closing' => $closing];
    }

    /**
     * Parses "1.234,56", "1,234.56", "1234.56", "-1.234,56", "1.234,56-", "(1.234,56)",
     * "1 234,56", "RON 1.234,56". Returns a positive/negative decimal string or null.
     */
    protected function parseAmount(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim($value);
        if ($v === '') {
            return null;
        }
        $negative = false;
        if (preg_match('/^\((.*)\)$/', $v, $m)) {
            $negative = true;
            $v = $m[1];
        }
        if (str_starts_with($v, '-') || str_ends_with($v, '-')) {
            $negative = true;
        }
        if (str_starts_with($v, '+')) {
            $v = substr($v, 1);
        }
        $v = preg_replace('/[^0-9.,]/u', '', $v) ?? '';
        if ($v === '' || !preg_match('/\d/', $v)) {
            return null;
        }

        $lastComma = strrpos($v, ',');
        $lastDot = strrpos($v, '.');
        if ($lastComma !== false && $lastDot !== false) {
            // Both present: the later one is the decimal separator.
            if ($lastComma > $lastDot) {
                $v = str_replace('.', '', $v);
                $v = str_replace(',', '.', $v);
            } else {
                $v = str_replace(',', '', $v);
            }
        } elseif ($lastComma !== false) {
            // Only commas: decimal if exactly one comma followed by 1-2 digits, else thousands.
            $after = substr($v, $lastComma + 1);
            if (substr_count($v, ',') === 1 && strlen($after) <= 2) {
                $v = str_replace(',', '.', $v);
            } else {
                $v = str_replace(',', '', $v);
            }
        } elseif ($lastDot !== false) {
            $after = substr($v, $lastDot + 1);
            if (substr_count($v, '.') === 1 && strlen($after) <= 2) {
                // decimal point
            } else {
                $v = str_replace('.', '', $v);
            }
        }

        if (!is_numeric($v)) {
            return null;
        }
        $result = number_format((float) $v, 2, '.', '');

        return $negative ? '-' . $result : $result;
    }

    protected function absAmount(?string $amount): string
    {
        if ($amount === null) {
            return '0.00';
        }

        return ltrim($amount, '-');
    }

    protected function looksLikeAmount(string $text): bool
    {
        return (bool) preg_match('/^[-+(]?\s*(\d{1,3}([.,\s]\d{3})*|\d+)([.,]\d{1,2})?\s*[-)]?$/', trim($text))
            && preg_match('/\d/', $text) === 1;
    }

    /**
     * @param string[] $formats PHP date formats tried in order
     */
    protected function parseDate(?string $value, array $formats = ['d.m.Y', 'd/m/Y', 'd-m-Y', 'Y-m-d', 'd.m.y', 'd/m/y']): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        $v = trim($value);
        foreach ($formats as $format) {
            $dt = \DateTimeImmutable::createFromFormat('!' . $format, $v);
            if ($dt !== false) {
                $errors = \DateTimeImmutable::getLastErrors();
                if ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) {
                    return $dt;
                }
            }
        }

        return null;
    }

    protected function looksLikeDate(string $text): bool
    {
        return (bool) preg_match('/^\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4}$/', trim($text))
            || (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($text));
    }

    protected function extractIban(string $text): ?string
    {
        $upper = strtoupper($text);
        if (preg_match(self::RO_IBAN_REGEX, $upper, $m)) {
            return $m[0];
        }
        // IBAN printed in groups of four ("RO69 BACX 0000 0040 0275 5000")
        if (preg_match('/\bRO\d{2}(?:\s?[A-Z0-9]{4}){5}\b/', $upper, $m)) {
            return preg_replace('/\s+/', '', $m[0]);
        }
        if (preg_match(self::IBAN_REGEX, $upper, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Finds the first IBAN in the given lines (joined without spaces so split IBANs still match).
     *
     * @param array<int, PdfWord[]> $lines
     */
    protected function findIban(array $lines, ?string $afterLabel = null): ?string
    {
        foreach ($lines as $line) {
            $text = $this->lineText($line);
            if ($afterLabel !== null && !str_contains($this->normalise($text), $this->normalise($afterLabel))) {
                continue;
            }
            $iban = $this->extractIban($text);
            if ($iban) {
                return $iban;
            }
        }

        return null;
    }

    protected function extractCui(string $text): ?string
    {
        if (preg_match('/\b(?:RO)?(\d{2,10})\b/', preg_replace('/(CUI|CIF|C\.U\.I\.|C\.I\.F\.|cod fiscal)[:\s]*/i', '', $text) ?? '', $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Currency code found in a line ("RON", "EUR", "USD", "GBP", "CHF", "HUF"...).
     */
    protected function extractCurrency(string $text): ?string
    {
        if (preg_match('/\b(RON|LEI|EUR|USD|GBP|CHF|HUF|PLN|CZK|SEK|NOK|DKK|CAD|AUD|JPY|BGN|TRY|MDL)\b/i', $text, $m)) {
            $c = strtoupper($m[1]);

            return $c === 'LEI' ? 'RON' : $c;
        }

        return null;
    }

    /**
     * Value following a label on the same line ("Sold initial: 1.234,56" => "1.234,56").
     */
    protected function valueAfterLabel(string $lineText, string $label): ?string
    {
        $pos = mb_stripos($this->normalise($lineText), $this->normalise($label));
        if ($pos === false) {
            return null;
        }
        // Work on the original string using the normalised offset (both have identical lengths after normalise? not guaranteed) — re-find loosely.
        $pattern = '/' . preg_quote($label, '/') . '\s*[:\-]?\s*(.+)$/iu';
        if (preg_match($pattern, $lineText, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Page bands (specs speak in PDF coordinates: "Top" measured from the page bottom)
    // ------------------------------------------------------------------

    /**
     * Words whose top edge, measured from the page bottom (PDF convention), lies in (minTop, maxTop).
     *
     * @return PdfWord[]
     */
    protected function wordsInBand(PdfPage $page, ?float $minTop = null, ?float $maxTop = null): array
    {
        $h = $page->height > 0 ? $page->height : 842.0;

        return array_values(array_filter($page->words, static function (PdfWord $w) use ($h, $minTop, $maxTop): bool {
            $top = $h - $w->y0;
            if ($minTop !== null && !($top > $minTop)) {
                return false;
            }
            if ($maxTop !== null && !($top < $maxTop)) {
                return false;
            }

            return true;
        }));
    }

    /**
     * Lines of every page restricted to a band, in reading order.
     *
     * @param PdfPage[] $pages
     * @return array<int, PdfWord[]>
     */
    protected function linesInBand(array $pages, ?float $minTop = null, ?float $maxTop = null, float $tolerance = 2.5): array
    {
        $out = [];
        foreach ($pages as $page) {
            foreach ($this->clusterer->cluster($this->wordsInBand($page, $minTop, $maxTop), $tolerance) as $line) {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * First word (reading order) matching a regex on its text.
     *
     * @param PdfWord[] $words
     */
    protected function firstWordMatching(array $words, string $regex): ?PdfWord
    {
        foreach ($words as $w) {
            if (preg_match($regex, $w->text)) {
                return $w;
            }
        }

        return null;
    }

    /**
     * ISO currency following a label word ("Valuta", "Cont", ...) within the next $lookahead words.
     *
     * @param PdfWord[] $words
     */
    protected function currencyAfterWord(array $words, string $label, int $lookahead = 15, array $iso = ['RON', 'EUR', 'USD', 'GBP', 'CHF', 'JPY', 'AUD', 'CAD', 'SEK', 'NOK', 'DKK']): ?string
    {
        $n = count($words);
        for ($i = 0; $i < $n; $i++) {
            if (strcasecmp($words[$i]->text, $label) === 0) {
                for ($j = $i + 1; $j < min($n, $i + 1 + $lookahead); $j++) {
                    $t = strtoupper(trim($words[$j]->text));
                    if (in_array($t, $iso, true)) {
                        return $t;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param PdfWord[] $words
     */
    protected function firstIsoCurrency(array $words, array $iso = ['RON', 'EUR', 'USD', 'GBP', 'CHF', 'JPY', 'AUD', 'CAD', 'SEK', 'NOK', 'DKK']): ?string
    {
        foreach ($words as $w) {
            $t = strtoupper(trim($w->text));
            if ($t === 'LEI') {
                return 'RON';
            }
            if (in_array($t, $iso, true)) {
                return $t;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Generic Debit/Credit table walker
    // ------------------------------------------------------------------

    /**
     * Walks table lines split into cells and builds transactions the way most
     * Romanian bank statements are laid out: a row starts when exactly one of the
     * debit/credit cells holds money; rows without money continue the previous
     * description; opening/closing balance rows seed and verify the running balance.
     *
     * Options (all optional):
     *  - date, desc, debit, credit: cell indexes (default 0,1,2,3)
     *  - money: callable(string): ?string   (default parseMoneyEn)
     *  - dateFormats: string[] for the strict date parse of the date cell
     *  - skip: callable(array $cells, string $joined): bool   lines to ignore entirely
     *  - isHeader: callable(array $cells): bool                repeated header rows
     *  - isOpening: callable(array $cells): bool
     *  - isClosing: callable(array $cells): bool
     *  - closingFrom: callable(array $cells): ?string          printed closing balance
     *  - stopAtClosing: bool (default true)
     *  - startInTable: bool (default true)  when false, rows are ignored until a header row is met
     *  - strictOpening: bool (default false) a second opening row must equal the running balance
     *  - reference: callable(string $description): array{0: string, 1: ?string}  cleaned description + reference
     *  - extraCells: callable(array $cells, bool $start, array &$acc): void   collect other columns (e.g. beneficiary)
     *  - finish: callable(array &$tx, array $acc): void   final touch on the transaction array before it is emitted
     *
     * @param array<int, string[]> $cellRows
     * @return array{transactions: PdfStatementTransaction[], closing: ?string, running: ?string, warnings: string[]}
     */
    protected function walkDebitCreditTable(array $cellRows, array $opt = []): array
    {
        $ci = ['date' => $opt['date'] ?? 0, 'desc' => $opt['desc'] ?? 1, 'debit' => $opt['debit'] ?? 2, 'credit' => $opt['credit'] ?? 3];
        $money = $opt['money'] ?? fn (?string $v) => $this->parseMoneyEn($v);
        $dateFormats = $opt['dateFormats'] ?? null;
        $skip = $opt['skip'] ?? null;
        $isHeader = $opt['isHeader'] ?? null;
        $isOpening = $opt['isOpening'] ?? null;
        $isClosing = $opt['isClosing'] ?? null;
        $closingFrom = $opt['closingFrom'] ?? null;
        $stopAtClosing = $opt['stopAtClosing'] ?? true;
        $inTable = $opt['startInTable'] ?? true;
        $strictOpening = $opt['strictOpening'] ?? false;
        $reference = $opt['reference'] ?? null;
        $extraCells = $opt['extraCells'] ?? null;
        $finish = $opt['finish'] ?? null;

        $transactions = [];
        $warnings = [];
        $current = null;
        $acc = [];
        $date = null;
        $running = $opt['running'] ?? null;
        $printedClosing = null;

        $flush = function () use (&$current, &$acc, &$transactions, $reference, $finish): void {
            if ($current === null) {
                return;
            }
            $desc = preg_replace('/\s{2,}/', ' ', trim($current['description'])) ?? '';
            $ref = $current['reference'] ?? null;
            if ($reference) {
                [$desc, $extractedRef] = $reference($desc);
                $ref = $ref ?? $extractedRef;
            }
            $current['description'] = $desc;
            $current['reference'] = $ref;
            if ($finish) {
                $finish($current, $acc);
            }
            $transactions[] = new PdfStatementTransaction(
                $current['date'],
                $current['description'],
                $current['debit'],
                $current['credit'],
                $current['reference'],
                null,
                $current['counterpartyName'] ?? null,
                $current['counterpartyIban'] ?? null,
                $current['closing'],
                null,
                $current['raw'],
            );
            $current = null;
            $acc = [];
        };

        foreach ($cellRows as $cells) {
            $joined = trim(implode(' ', $cells));
            if ($skip && $skip($cells, $joined)) {
                continue;
            }
            if ($isHeader && $isHeader($cells)) {
                $inTable = true;
                continue;
            }
            if (!$inTable) {
                continue;
            }
            if ($isClosing && $isClosing($cells)) {
                $printedClosing = $closingFrom ? $closingFrom($cells) : ($money($cells[$ci['credit']] ?? '') ?? $money($cells[$ci['debit']] ?? ''));
                $flush();
                if ($stopAtClosing) {
                    $inTable = false;
                    if ($opt['breakAtClosing'] ?? false) {
                        break;
                    }
                }
                continue;
            }
            $debitCell = $cells[$ci['debit']] ?? '';
            $creditCell = $cells[$ci['credit']] ?? '';
            $descCell = $cells[$ci['desc']] ?? '';
            if ($isOpening && $isOpening($cells)) {
                $v = $money($creditCell);
                if ($v === null) {
                    $d = $money($debitCell);
                    $v = $d === null ? null : bcmul($d, '-1', 2);
                }
                if ($v === null) {
                    continue;
                }
                if ($running !== null && $strictOpening && bccomp($v, $running, 2) !== 0) {
                    $warnings[] = sprintf('Sold intermediar (%s) diferit de soldul calculat (%s).', $v, $running);
                }
                $running = $v;
                continue;
            }

            $debit = $money($debitCell);
            $credit = $money($creditCell);
            $exactlyOne = (($debit !== null) !== ($credit !== null)); // exactly one side holds money
            if ($exactlyOne) {
                $flush();
                $parsedDate = $dateFormats ? $this->parseDate($cells[$ci['date']] ?? '', $dateFormats) : $this->parseDateStrict($cells[$ci['date']] ?? '');
                if ($parsedDate) {
                    $date = $parsedDate;
                }
                if ($date === null) {
                    // amount without any date so far: cannot place it
                    $warnings[] = 'Rand cu suma fara data: ' . $joined;
                    continue;
                }
                $isCredit = $credit !== null;
                $amount = $this->bcAbs($isCredit ? $credit : $debit);
                $opening = $running ?? '0.00';
                $closing = $isCredit ? bcadd($opening, $amount, 2) : bcsub($opening, $amount, 2);
                $running = $closing;
                $current = [
                    'date' => $date,
                    'description' => trim($descCell),
                    'debit' => $isCredit ? '0.00' : $amount,
                    'credit' => $isCredit ? $amount : '0.00',
                    'reference' => null,
                    'opening' => $opening,
                    'closing' => $closing,
                    'raw' => [$joined],
                ];
                if ($extraCells) {
                    $extraCells($cells, true, $acc);
                }
                continue;
            }
            if ($current !== null) {
                if (trim($descCell) !== '') {
                    $current['description'] .= ' ' . trim($descCell);
                }
                $current['raw'][] = $joined;
                if ($extraCells) {
                    $extraCells($cells, false, $acc);
                }
            }
        }
        $flush();

        return ['transactions' => $transactions, 'closing' => $printedClosing, 'running' => $running, 'warnings' => $warnings];
    }

    /**
     * Warning text when the printed closing balance and the computed one disagree.
     */
    protected function closingMismatch(?string $printed, ?string $computed): ?string
    {
        if ($printed === null || $computed === null) {
            return null;
        }
        if (bccomp($printed, $computed, 2) === 0) {
            return null;
        }

        return sprintf(
            'Soldul calculat (%s) nu corespunde cu soldul final din extras (%s). Verificati tranzactiile importate.',
            number_format((float) $computed, 2, ',', '.'),
            number_format((float) $printed, 2, ',', '.'),
        );
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /**
     * Checks opening + credits - debits == closing (tolerance 0.01) and returns a warning text or null.
     *
     * @param PdfStatementTransaction[] $transactions
     */
    protected function reconcile(?string $opening, ?string $closing, array $transactions): ?string
    {
        if ($opening === null || $closing === null) {
            return null;
        }
        $sum = $opening;
        foreach ($transactions as $tx) {
            $sum = bcadd($sum, $tx->credit, 2);
            $sum = bcsub($sum, $tx->debit, 2);
        }
        if (bccomp(ltrim(bcsub($sum, $closing, 2), '-'), '0.01', 2) > 0) {
            return sprintf(
                'Soldul calculat (%s) nu corespunde cu soldul final din extras (%s). Este posibil ca unele tranzactii sa nu fi fost citite.',
                number_format((float) $sum, 2, ',', '.'),
                number_format((float) $closing, 2, ',', '.'),
            );
        }

        return null;
    }

    /**
     * Lines that are page furniture on every bank statement.
     */
    protected function isPageFurniture(string $lineText): bool
    {
        $t = $this->normalise($lineText);

        return $t === ''
            || (bool) preg_match('/^(pagina|page|pag\.?)\s*\d+(\s*(din|of|\/)\s*\d+)?$/', $t)
            || (bool) preg_match('/^\d+\s*(din|of|\/)\s*\d+$/', $t);
    }
}
