<?php

namespace App\Service\Borderou\Pdf\Bank;

use App\Service\Borderou\Pdf\AbstractPdfStatementParser;
use App\Service\Borderou\Pdf\PdfStatement;
use App\Service\Borderou\Pdf\PdfStatementNotRecognizedException;
use App\Service\Borderou\Pdf\PdfStatementTransaction;
use App\Service\Borderou\Pdf\PdfWord;

/**
 * Patria Bank bilingual statement: table
 * "Data valutei / Data decontarii / Referinta / Tranzactie debit / Tranzactie credit / Sold"
 * (English sub-header on the next line), amounts 1,234.56, dates dd.MM.yyyy,
 * balances in "Sold initial / Opening balance" and "Sold / Balance" lines.
 */
class PatriaPdfParser extends AbstractPdfStatementParser
{
    private const ISO = ['RON', 'EUR', 'USD', 'GBP', 'CHF', 'JPY', 'AUD', 'CAD', 'SEK', 'NOK', 'DKK'];
    private const STOP_WORDS = ['total', 'sold', 'perioada'];

    public function getBankKey(): string
    {
        return 'patria';
    }

    public function getBankLabel(): string
    {
        return 'Patria Bank';
    }

    public function score(array $page1Words): int
    {
        $t = $this->normalise(implode(' ', $page1Words));
        if (str_contains($t, 'www.patriabank.ro')) {
            return 100;
        }
        $a = str_contains($t, 'patria bank s.a');
        $b = str_contains($t, 'globalworth plaza');
        if ($a && $b) {
            return 100;
        }

        return ($a || $b) ? 50 : 0;
    }

    public function parse(array $pages): array
    {
        $page1 = $pages[0];
        $firstPageWords = $this->wordsInBand($page1, 90.0);
        usort($firstPageWords, static fn (PdfWord $a, PdfWord $b) => [$a->y0, $a->x0] <=> [$b->y0, $b->x0]);

        // --- metadata ----------------------------------------------------
        $iban = null;
        $rawIban = $this->extractAfterSpecificWords($firstPageWords, 'Account', 'number:', true);
        if ($rawIban !== null) {
            $clean = preg_replace('/[^A-Za-z0-9]/', '', $rawIban) ?? '';
            if (strlen($clean) === 24) {
                $iban = strtoupper($clean);
            }
        }
        $leftWords = array_values(array_filter($firstPageWords, static fn (PdfWord $w) => $w->x0 < 400));
        $holder = $this->extractAfterSpecificWords($leftWords, 'Customer', 'name:');
        $fiscalCode = $this->extractAfterSpecificWords($leftWords, 'Registration', 'Number:');
        if ($fiscalCode !== null) {
            $fiscalCode = trim($fiscalCode, ':;., ');
            if ($fiscalCode === '') {
                $fiscalCode = null;
            }
        }
        $currency = $this->currencyAfterLabel($firstPageWords) ?? $this->firstIsoCurrency($firstPageWords, self::ISO) ?? 'RON';

        // --- table lines (all pages, Top > 90) ----------------------------
        $lines = [];
        foreach ($pages as $page) {
            foreach ($this->clusterer->cluster($this->wordsInBand($page, 90.0), 2.5) as $line) {
                $lines[] = $line;
            }
        }

        $headerIdx = null;
        foreach ($lines as $i => $line) {
            if ($this->isHeaderLine($line)) {
                $headerIdx = $i;
                break;
            }
        }
        if ($headerIdx === null) {
            throw new PdfStatementNotRecognizedException('Nu am putut identifica antetul tabelului (Data valutei / Data decontarii / Referinta / Tranzactie debit / Tranzactie credit / Sold) in PDF-ul Patria Bank. Este posibil ca formatul extrasului sa fi fost modificat.');
        }
        $bounds = $this->boundaries($lines[$headerIdx]);

        // --- opening / closing balances (searched on every line) ----------
        $warnings = [];
        $running = null;
        $printedClosing = null;
        $openingIdx = null;
        $closingIdx = null;
        foreach ($lines as $i => $line) {
            $tokens = array_map(fn (PdfWord $w) => $this->normalise($w->text), $line);
            if ($openingIdx === null && in_array('sold', $tokens, true)) {
                foreach ($tokens as $t) {
                    if (str_starts_with($t, 'initial')) {
                        $v = $this->lastMoney($line);
                        if ($v !== null) {
                            $running = $v;
                            $openingIdx = $i;
                        }
                        break;
                    }
                }
            }
            if ($closingIdx === null) {
                $n = count($line);
                for ($j = 0; $j + 2 < $n; $j++) {
                    if (strcasecmp($line[$j]->text, 'Sold') === 0 && $line[$j + 1]->text === '/' && stripos($line[$j + 2]->text, 'Balance') === 0) {
                        $v = $this->lastMoney($line);
                        if ($v !== null) {
                            $printedClosing = $v;
                            $closingIdx = $i;
                        }
                        break;
                    }
                }
            }
        }
        $opening = $running;

        // --- rows ---------------------------------------------------------
        $transactions = [];
        $current = null;
        $date = null;
        $inFooter = false;
        $flush = function () use (&$current, &$transactions): void {
            if ($current === null) {
                return;
            }
            [$desc, $ref] = $this->extractReference($current['description']);
            $transactions[] = new PdfStatementTransaction(
                $current['date'],
                $desc,
                $current['debit'],
                $current['credit'],
                $ref,
                null,
                null,
                null,
                $current['closing'],
                null,
                $current['raw'],
            );
            $current = null;
        };

        $count = count($lines);
        for ($i = $headerIdx + 2; $i < $count; $i++) {
            if ($i === $openingIdx || $i === $closingIdx) {
                continue;
            }
            $line = $lines[$i];
            $cells = $this->splitByBoundaries($line, $bounds);
            $joined = trim(implode(' ', $cells));
            if ($this->isTableHeaderCells($cells)) {
                $inFooter = false;
                continue;
            }
            if ($this->isFooterDisclaimer($cells)) {
                $inFooter = true;
                continue;
            }
            if ($inFooter) {
                continue;
            }
            $datePresent = $this->looksLikeDate($cells[1]);
            $debit = $this->nonZeroMoney($cells[3]);
            $credit = $this->nonZeroMoney($cells[4]);
            $hasAmount = ($debit !== null) !== ($credit !== null);
            if ($hasAmount) {
                $flush();
                if ($datePresent) {
                    $parsed = $this->parseDate($cells[1], ['d.m.Y']);
                    if ($parsed !== null) {
                        $date = $parsed;
                    }
                }
                if ($date === null) {
                    $warnings[] = 'Rand cu suma fara data: ' . $joined;
                    continue;
                }
                $isCredit = $credit !== null;
                $amount = $this->bcAbs($isCredit ? $credit : $debit);
                $before = $running ?? '0.00';
                $closing = $isCredit ? bcadd($before, $amount, 2) : bcsub($before, $amount, 2);
                $running = $closing;
                $current = [
                    'date' => $date,
                    'description' => trim($cells[2]),
                    'debit' => $isCredit ? '0.00' : $amount,
                    'credit' => $isCredit ? $amount : '0.00',
                    'closing' => $closing,
                    'raw' => [$joined],
                ];
                continue;
            }
            if ($current !== null) {
                if (trim($cells[2]) !== '') {
                    $current['description'] .= ' ' . trim($cells[2]);
                }
                $current['raw'][] = $joined;
            }
        }
        $flush();

        if ($printedClosing !== null && bccomp($printedClosing, '0', 2) !== 0) {
            if ($mismatch = $this->closingMismatch($printedClosing, $running)) {
                $warnings[] = $mismatch;
            }
        }
        if ($transactions === []) {
            $warnings[] = 'Nu a fost gasita nicio tranzactie in extras.';
        }

        return [new PdfStatement(
            $this->getBankKey(),
            $this->getBankLabel(),
            $iban,
            $currency,
            $transactions,
            $holder,
            $fiscalCode,
            $opening,
            $printedClosing ?? $running,
            null,
            null,
            $warnings,
        )];
    }

    // ------------------------------------------------------------------

    /**
     * @param PdfWord[] $line
     */
    private function isHeaderLine(array $line): bool
    {
        $data = 0;
        $ref = false;
        $tranz = 0;
        $sold = false;
        foreach ($line as $w) {
            $t = $this->normalise($w->text);
            if ($t === 'data') {
                $data++;
            } elseif (str_starts_with($t, 'referinta')) {
                $ref = true;
            } elseif (str_starts_with($t, 'tranzactie')) {
                $tranz++;
            } elseif ($t === 'sold') {
                $sold = true;
            }
        }

        return $data >= 2 && $ref && $tranz >= 2 && $sold;
    }

    /**
     * @param PdfWord[] $header
     * @return float[]
     */
    private function boundaries(array $header): array
    {
        $data = [];
        $tranz = [];
        $ref = $debit = $credit = $sold = null;
        foreach ($header as $w) {
            $t = $this->normalise($w->text);
            if ($t === 'data') {
                $data[] = $w;
            } elseif ($ref === null && str_starts_with($t, 'referinta')) {
                $ref = $w;
            } elseif (str_starts_with($t, 'tranzactie')) {
                $tranz[] = $w;
            } elseif ($debit === null && $t === 'debit') {
                $debit = $w;
            } elseif ($credit === null && $t === 'credit') {
                $credit = $w;
            } elseif ($sold === null && $t === 'sold') {
                $sold = $w;
            }
        }
        usort($data, static fn (PdfWord $a, PdfWord $b) => $a->x0 <=> $b->x0);
        usort($tranz, static fn (PdfWord $a, PdfWord $b) => $a->x0 <=> $b->x0);
        if (count($data) < 2 || count($tranz) < 2 || !$ref || !$debit || !$credit || !$sold) {
            throw new PdfStatementNotRecognizedException('Nu am putut identifica coloanele tabelului in PDF-ul Patria Bank. Este posibil ca formatul extrasului sa fi fost modificat.');
        }

        return [
            -INF,
            $data[1]->x0 - 8.0,
            $ref->x0 - 8.0,
            $tranz[0]->x0 - 8.0,
            ($debit->x1 + $tranz[1]->x0) / 2,
            ($credit->x1 + $sold->x0) / 2,
        ];
    }

    /**
     * @param string[] $cells
     */
    private function isTableHeaderCells(array $cells): bool
    {
        if (count($cells) < 5 || stripos($cells[0], 'Data') !== 0) {
            return false;
        }
        $ref = false;
        $tranz = false;
        foreach ($cells as $c) {
            $n = $this->normalise($c);
            if (str_contains($n, 'referinta')) {
                $ref = true;
            }
            if (str_contains($n, 'tranzactie')) {
                $tranz = true;
            }
        }

        return $ref && $tranz;
    }

    /**
     * @param string[] $cells
     */
    private function isFooterDisclaimer(array $cells): bool
    {
        foreach ($cells as $c) {
            if (str_contains($this->normalise($c), 'datele inscrise')) {
                return true;
            }
        }

        return false;
    }

    private function nonZeroMoney(string $cell): ?string
    {
        $v = $this->parseMoneyEn($cell);
        if ($v === null || bccomp($v, '0', 2) === 0) {
            return null;
        }

        return $v;
    }

    /**
     * @param PdfWord[] $line
     */
    private function lastMoney(array $line): ?string
    {
        for ($i = count($line) - 1; $i >= 0; $i--) {
            $v = $this->parseMoneyEn($line[$i]->text);
            if ($v !== null) {
                return $v;
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function extractReference(string $description): array
    {
        $desc = preg_replace('/\s{2,}/', ' ', trim($description)) ?? '';
        if (preg_match('/^(\d{6,})(?=:)/', $desc, $m)) {
            $rest = substr($desc, strlen($m[1]));
            $rest = ltrim($rest, ':');

            return [trim($rest), $m[1]];
        }

        return [$desc, null];
    }

    /**
     * Words following "first second" on one line, up to the first stop word.
     *
     * @param PdfWord[] $words
     */
    private function extractAfterSpecificWords(array $words, string $first, string $second = '', bool $concatenate = false): ?string
    {
        foreach ($this->clusterer->cluster($words, 1.5) as $line) {
            $n = count($line);
            if ($n < 2) {
                continue;
            }
            for ($i = 0; $i < $n - 1; $i++) {
                if (stripos($line[$i]->text, $first) !== 0) {
                    continue;
                }
                $start = $i + 1;
                if ($second !== '') {
                    if (stripos($line[$i + 1]->text, $second) !== 0) {
                        continue;
                    }
                    $start = $i + 2;
                }
                $parts = [];
                for ($j = $start; $j < $n; $j++) {
                    $t = $this->normalise($line[$j]->text);
                    foreach (self::STOP_WORDS as $stop) {
                        if (str_starts_with($t, $stop)) {
                            break 2;
                        }
                    }
                    $parts[] = $line[$j]->text;
                }
                if ($parts !== []) {
                    $value = trim(implode($concatenate ? '' : ' ', $parts));
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param PdfWord[] $words
     */
    private function currencyAfterLabel(array $words): ?string
    {
        $lines = $this->clusterer->cluster($words, 2.5);
        $flat = [];
        foreach ($lines as $line) {
            foreach ($line as $w) {
                $flat[] = $w;
            }
        }
        $n = count($flat);
        for ($i = 0; $i < $n - 1; $i++) {
            if (stripos($flat[$i]->text, 'Currency:') !== 0) {
                continue;
            }
            for ($j = $i + 1; $j < $n; $j++) {
                $t = trim($flat[$j]->text);
                if ($t === '') {
                    continue;
                }
                $t = strtoupper(rtrim($t, ':;.,'));

                return $t === 'LEI' ? 'RON' : $t;
            }
        }

        return null;
    }
}
