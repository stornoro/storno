<?php

namespace App\Service\Borderou\Pdf\Bank;

use App\Service\Borderou\Pdf\AbstractPdfStatementParser;
use App\Service\Borderou\Pdf\PdfStatement;
use App\Service\Borderou\Pdf\PdfStatementNotRecognizedException;
use App\Service\Borderou\Pdf\PdfStatementTransaction;
use App\Service\Borderou\Pdf\PdfWord;

/**
 * Garanti BBVA "Extras de cont": table "Data / Detalii / Suma / Sold" with one signed amount
 * column (negative = debit), amounts 1.234,56, dates dd/MM/yyyy or dd.MM.yyyy. The description
 * headline is printed on its own line above the dated row; the date, amount and balance follow
 * on the next line(s). Balances come from "Sold initial : x" and "... Soldul final : x".
 */
class GarantiPdfParser extends AbstractPdfStatementParser
{
    private const ISO = ['RON', 'EUR', 'USD', 'GBP', 'CHF', 'JPY', 'AUD', 'CAD', 'SEK', 'NOK', 'DKK', 'HUF', 'PLN'];
    private const IBAN_REGEX_WORD = '/\b[A-Z]{2}\d{2}[A-Z0-9]{10,30}\b/';
    private const START_KEYWORDS = ['plata', 'incasare', 'schimb', 'transfer', 'comision', 'taxa', 'dobanda', 'retragere', 'cumparatura', 'detalii'];
    private const NOT_START_PREFIXES = ['ordonat', 'iban', 'referinta', 'descriere', 'explicatii'];
    private const NOT_START_CONTAINS = ['txid', 'notprovided', 'msgid', 'roc/', 'rfb/', 'svclvl', 'uri/'];
    private const LEGAL_FORMS = ['srl', 's.r.l.', 's.r.l', 'srl-d', 'sa', 's.a.', 'pfa', 'p.f.a.', 'snc', 'scs', 'sca'];

    public function getBankKey(): string
    {
        return 'garanti';
    }

    public function getBankLabel(): string
    {
        return 'Garanti BBVA';
    }

    public function score(array $page1Words): int
    {
        $t = $this->normalise(implode(' ', $page1Words));
        if (str_contains($t, 'www.garantibbva.ro')) {
            return 100;
        }
        $a = str_contains($t, 'garanti bank s.a.');
        $b = str_contains($t, 'fabrica de glucoza');
        if ($a && $b) {
            return 100;
        }

        return ($a || $b) ? 50 : 0;
    }

    public function parse(array $pages): array
    {
        $page1 = $pages[0];
        $h = $page1->height > 0 ? $page1->height : 842.0;
        $first = $this->wordsInBand($page1, 90.0);
        $this->sortReading($first);
        $headerWords = $this->wordsAboveExtrasDeCont($first);

        $iban = $this->extractHeaderIban($headerWords);
        $block = array_values(array_filter($first, static fn (PdfWord $w) => $w->x0 < 345.0 && $w->y0 < $h - 700.0));
        $holder = $this->holderAfterNumeClient($block) ?? $this->holderFallback($block);
        $currency = $this->currencyAfterValuta($headerWords) ?? $this->firstIsoCurrency($headerWords, self::ISO) ?? 'RON';

        // --- table -------------------------------------------------------
        $lines = $this->linesInBand($pages, 60.0, 815.0);
        $headerIdx = null;
        foreach ($lines as $i => $w) {
            $n = count($w);
            if (($n === 4 || $n === 5) && strcasecmp($w[0]->text, 'Data') === 0 && stripos($w[1]->text, 'Detalii') === 0
                && $this->hasWord($w, 'Suma') && $this->hasWord($w, 'Sold')) {
                $headerIdx = $i;
                break;
            }
        }
        if ($headerIdx === null) {
            throw new PdfStatementNotRecognizedException('Nu am putut identifica antetul tabelului (Data / Detalii / Suma / Sold) in PDF-ul Garanti. Este posibil ca formatul extrasului sa fi fost modificat.');
        }
        $detalii = $this->firstWordMatching($lines[$headerIdx], '/^Detalii$/i') ?? $lines[$headerIdx][1];
        $suma = $this->firstWordMatching($lines[$headerIdx], '/^Suma$/i');
        if (!$suma) {
            throw new PdfStatementNotRecognizedException('Antetul tabelului Garanti este incomplet.');
        }
        $bounds = [-INF, $detalii->x0 - 8.0, $suma->x0 - 44.0, $suma->x1 - 8.0];

        $opening = null;
        $printedClosing = null;
        $closingIdx = null;
        foreach ($lines as $i => $w) {
            if ($opening === null && count($w) === 5 && strcasecmp($w[0]->text, 'Sold') === 0 && str_starts_with($this->normalise($w[1]->text), 'initial')) {
                $opening = $this->parseGarantiMoney($w[3]->text);
            }
            if (count($w) === 8 && strcasecmp($w[3]->text, 'Soldul') === 0 && stripos($w[4]->text, 'final') === 0) {
                $printedClosing = $this->parseGarantiMoney($w[6]->text);
                $closingIdx = $i;
            }
        }

        $transactions = [];
        $warnings = [];
        $running = $opening;
        $current = null;
        $prevLineWasStart = false;
        $amountSeenSinceStart = false;

        $flush = function () use (&$current, &$transactions, &$warnings): void {
            if ($current === null) {
                return;
            }
            $desc = trim(preg_replace('/\s{2,}/', ' ', $current['description']) ?? '');
            $hasAmount = bccomp($current['amount'], '0', 2) > 0;
            if ($current['date'] === null || !$hasAmount || $desc === '') {
                if ($hasAmount && $desc !== '') {
                    $warnings[] = 'Tranzactie fara data ignorata: ' . $desc;
                }
                $current = null;

                return;
            }
            $transactions[] = new PdfStatementTransaction(
                $current['date'],
                $desc,
                $current['credit'] ? '0.00' : $current['amount'],
                $current['credit'] ? $current['amount'] : '0.00',
                $this->extractReference($desc),
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
        for ($i = $headerIdx + 1; $i < $count; $i++) {
            if ($closingIdx !== null && $i >= $closingIdx) {
                break;
            }
            $line = $lines[$i];
            $cells = $this->splitByBoundaries($line, $bounds);
            if ($this->isTableHeader($cells)) {
                continue;
            }
            $joined = trim(implode(' ', $cells));
            $desc = trim($cells[1] ?? '');
            $date = $this->parseDateStrict($cells[0] ?? '');
            $amount = $this->parseGarantiMoney($cells[2] ?? '');
            $hasAmount = $amount !== null;
            $creditPresent = $hasAmount && bccomp($amount, '0', 2) >= 0;
            $isUppercase = $this->isRowUppercase($line);
            $isStart = $this->isTransactionStart($desc, $isUppercase);

            $complete = $current !== null && $current['date'] !== null && bccomp($current['amount'], '0', 2) > 0;
            $newTx = $isStart && (!$prevLineWasStart || $amountSeenSinceStart);
            if (!$newTx && $date !== null && $hasAmount && $complete) {
                $newTx = true;
            }
            if ($isStart) {
                $prevLineWasStart = true;
                $amountSeenSinceStart = false;
            } else {
                $prevLineWasStart = false;
            }
            if ($hasAmount) {
                $amountSeenSinceStart = true;
            }

            if ($newTx) {
                $flush();
                $current = ['description' => $desc, 'date' => null, 'amount' => '0.00', 'credit' => false, 'closing' => null, 'raw' => []];
                $complete = false;
            } elseif ($current !== null && $desc !== '') {
                $current['description'] .= ' ' . $desc;
            }
            if ($current === null) {
                continue;
            }
            $current['raw'][] = $joined;
            if (($date !== null || $hasAmount) && !$complete) {
                if ($date !== null) {
                    $current['date'] = $date;
                }
                if ($hasAmount && bccomp($current['amount'], '0', 2) === 0) {
                    $abs = $this->bcAbs($amount);
                    $open = $running ?? '0.00';
                    $closing = $creditPresent ? bcadd($open, $abs, 2) : bcsub($open, $abs, 2);
                    $running = $closing;
                    $current['amount'] = $abs;
                    $current['credit'] = $creditPresent;
                    $current['closing'] = $closing;
                }
            }
        }
        $flush();

        if ($mismatch = $this->closingMismatch($printedClosing, $running)) {
            $warnings[] = $mismatch;
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
            null,
            $opening,
            $printedClosing,
            null,
            null,
            $warnings,
        )];
    }

    // ------------------------------------------------------------------
    // Row helpers
    // ------------------------------------------------------------------

    /**
     * Repeated column header: cells Data / Detalii / Suma / Sold, compared both on the raw text
     * and with runs of identical characters collapsed (bold text may be extracted doubled).
     *
     * @param string[] $cells
     */
    private function isTableHeader(array $cells): bool
    {
        if (count($cells) < 4) {
            return false;
        }
        $raw = array_map(fn (string $c) => $this->normalise(trim($c)), $cells);
        $dd = array_map(fn (string $c) => $this->dedup($c), $raw);
        $starts = static fn (int $i, string $label): bool => str_starts_with($raw[$i], $label) || str_starts_with($dd[$i], preg_replace('/(.)\1+/u', '$1', $label));
        $contains = static function (string $label) use ($raw, $dd): bool {
            foreach ($raw as $i => $c) {
                if (str_contains($c, $label) || str_contains($dd[$i], $label)) {
                    return true;
                }
            }

            return false;
        };

        return $starts(0, 'data') && $starts(1, 'detalii') && $contains('suma') && $contains('sold');
    }

    private function isTransactionStart(string $text, bool $isUppercase): bool
    {
        $t = $this->normalizePdfText($text);
        $lower = mb_strtolower($t);
        if ($this->isDefinitelyNotStart($lower)) {
            return false;
        }
        $score = $isUppercase ? 3 : 0;
        $letters = preg_match_all('/\p{L}/u', $t);
        $upper = preg_match_all('/\p{Lu}/u', $t);
        if ($letters > 0 && $upper > 0.6 * $letters) {
            $score += 2;
        }
        $keyword = false;
        $plain = $this->normalise($lower);
        foreach (self::START_KEYWORDS as $kw) {
            if (str_starts_with($plain, $kw)) {
                $score += 6;
                $keyword = true;
                break;
            }
        }
        if (mb_strlen($t) < 60) {
            $score += 1;
        }

        return ($isUppercase || $keyword) && $score >= 6;
    }

    private function isDefinitelyNotStart(string $lower): bool
    {
        if ($lower === '') {
            return true;
        }
        $plain = $this->normalise($lower);
        foreach (self::NOT_START_PREFIXES as $p) {
            if (str_starts_with($plain, $p)) {
                return true;
            }
        }
        foreach (self::NOT_START_CONTAINS as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }
        if (preg_match('/\b[a-z]{2}\d{2}[a-z0-9]{10,}\b/', $lower)) {
            return true;
        }
        $digits = preg_match_all('/\d/', $lower);
        $slashes = substr_count($lower, '/');
        $letters = preg_match_all('/\p{L}/u', $lower);
        $spaces = substr_count($lower, ' ');

        return $digits >= 8 && $slashes >= 2 && $letters > 0 && $spaces <= 1;
    }

    /**
     * @param PdfWord[] $line
     */
    private function isRowUppercase(array $line): bool
    {
        $text = $this->lineText($line);
        $letters = preg_match_all('/\p{L}/u', $text);
        if ($letters === 0) {
            return false;
        }
        $upper = preg_match_all('/\p{Lu}/u', $text);

        return $upper >= 0.95 * $letters;
    }

    /**
     * Longest "Referinta: <timestamp-id | 10-20 digits>" value in the description.
     */
    private function extractReference(string $desc): ?string
    {
        if (!preg_match_all('/\bReferin(?:ta|ță|ţă)\s*:\s*(\d{4}-\d{2}-\d{2}-\d{2}\.\d{2}\.\d{2}\.\d+|\d{10,20})/iu', $desc, $m)) {
            return null;
        }
        $best = null;
        foreach ($m[1] as $ref) {
            if ($best === null || strlen($ref) > strlen($best)) {
                $best = $ref;
            }
        }

        return $best;
    }

    /**
     * Garanti money: keep digits . , -; when both separators are present the last one is the
     * decimal separator; a lone comma is the decimal separator; a lone dot is kept as decimal.
     */
    private function parseGarantiMoney(string $v): ?string
    {
        $v = str_replace(["\u{2212}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{00AD}"], '-', $v);
        $v = preg_replace('/[^0-9.,\-]/', '', $v) ?? '';
        if ($v === '' || !preg_match('/\d/', $v)) {
            return null;
        }
        $lastDot = strrpos($v, '.');
        $lastComma = strrpos($v, ',');
        if ($lastDot !== false && $lastComma !== false) {
            if ($lastComma > $lastDot) {
                $v = str_replace('.', '', $v);
                $v = str_replace(',', '.', $v);
            } else {
                $v = str_replace(',', '', $v);
            }
        } elseif ($lastComma !== false) {
            $v = str_replace(',', '.', $v);
        }
        if (!preg_match('/^-?\d+(\.\d+)?$/', $v)) {
            return null;
        }

        return number_format((float) $v, 2, '.', '');
    }

    // ------------------------------------------------------------------
    // Metadata helpers
    // ------------------------------------------------------------------

    /**
     * Words on the lines above the "Extras de cont" title (all words when the title is absent).
     *
     * @param PdfWord[] $first
     * @return PdfWord[]
     */
    private function wordsAboveExtrasDeCont(array $first): array
    {
        $out = [];
        foreach ($this->clusterer->cluster($first, 1.5) as $line) {
            $t = $this->normalise($this->lineText($line));
            if (str_contains($t, 'extras') && str_contains($t, 'de') && str_contains($t, 'cont')) {
                $this->sortReading($out);

                return $out;
            }
            foreach ($line as $w) {
                $out[] = $w;
            }
        }
        $this->sortReading($out);

        return $out;
    }

    /**
     * @param PdfWord[] $headerWords
     */
    private function extractHeaderIban(array $headerWords): ?string
    {
        $lines = $this->clusterer->cluster($headerWords, 1.5);
        foreach ($lines as $line) {
            $iban = $this->ibanFromLine($line);
            if ($iban !== null) {
                return $iban;
            }
        }
        for ($i = 0; $i + 1 < count($lines); $i++) {
            $merged = array_merge($lines[$i], $lines[$i + 1]);
            $this->sortReading($merged);
            $iban = $this->ibanFromLine($merged);
            if ($iban !== null) {
                return $iban;
            }
        }

        return null;
    }

    /**
     * @param PdfWord[] $line
     */
    private function ibanFromLine(array $line): ?string
    {
        $tokens = array_map(static fn (PdfWord $w) => trim(trim($w->text), ":;,.-\u{2013}"), $line);
        foreach ($tokens as $t) {
            if (preg_match(self::IBAN_REGEX_WORD, $t, $m)) {
                return strtoupper($m[0]);
            }
        }
        $joined = implode(' ', $tokens);
        if (preg_match('/\bRO\s*\d{2}(?:\s*[A-Z0-9]{4}){5}\b/i', $joined, $m)) {
            return strtoupper(preg_replace('/\s+/', '', $m[0]) ?? $m[0]);
        }
        $glued = implode('', $tokens);
        if (preg_match(self::IBAN_REGEX_WORD, $glued, $m)) {
            return strtoupper($m[0]);
        }

        return null;
    }

    /**
     * "Nume client : EXEMPLU SRL" => "EXEMPLU SRL".
     *
     * @param PdfWord[] $block
     */
    private function holderAfterNumeClient(array $block): ?string
    {
        foreach ($this->clusterer->cluster($block, 1.5) as $line) {
            $n = count($line);
            if ($n < 3) {
                continue;
            }
            for ($i = 0; $i + 1 < $n; $i++) {
                if (stripos($line[$i]->text, 'Nume') === 0 && stripos($line[$i + 1]->text, 'client') === 0) {
                    $rest = array_slice($line, $i + 2);
                    $name = trim(str_replace(':', '', implode(' ', array_map(static fn (PdfWord $w) => $w->text, $rest))));

                    return $name === '' ? null : $name;
                }
            }
        }

        return null;
    }

    /**
     * Fallback: tokens after "client" up to and including the legal form.
     *
     * @param PdfWord[] $block
     */
    private function holderFallback(array $block): ?string
    {
        $texts = array_map(static fn (PdfWord $w) => $w->text, $block);
        $start = null;
        foreach ($texts as $i => $t) {
            if (strcasecmp($t, 'client') === 0) {
                $start = $i + 1;
                break;
            }
        }
        if ($start === null) {
            return null;
        }
        $parts = [];
        for ($i = $start; $i < count($texts); $i++) {
            $t = trim($texts[$i]);
            if ($t === '' || $t === ':') {
                continue;
            }
            $parts[] = $t;
            if (in_array(strtolower($t), self::LEGAL_FORMS, true)) {
                break;
            }
        }
        $name = trim(implode(' ', $parts));

        return $name === '' ? null : $name;
    }

    /**
     * "Valuta : RON" (the ":" token is skipped) or "Valuta: RON"; LEI => RON.
     *
     * @param PdfWord[] $headerWords
     */
    private function currencyAfterValuta(array $headerWords): ?string
    {
        $flat = [];
        foreach ($this->clusterer->cluster($headerWords, 2.5) as $line) {
            foreach ($line as $w) {
                $flat[] = $w->text;
            }
        }
        $n = count($flat);
        for ($i = 0; $i + 1 < $n; $i++) {
            $label = strtolower($flat[$i]);
            if ($label !== 'valuta' && $label !== 'valuta:') {
                continue;
            }
            $from = $label === 'valuta' ? $i + 2 : $i + 1;
            for ($j = $from; $j < $n; $j++) {
                $t = rtrim(trim($flat[$j]), ':;.,');
                if ($t === '') {
                    continue;
                }

                return strcasecmp($t, 'LEI') === 0 ? 'RON' : strtoupper($t);
            }
        }

        return null;
    }

    private function dedup(string $s): string
    {
        return preg_replace('/(.)\1+/us', '$1', $s) ?? $s;
    }

    private function normalizePdfText(string $s): string
    {
        return trim(str_replace(["\u{FB01}", "\u{FB02}", "\u{00A0}", "\u{200B}"], ['fi', 'fl', ' ', ''], $s));
    }

    /**
     * @param PdfWord[] $words
     */
    private function hasWord(array $words, string $text): bool
    {
        foreach ($words as $w) {
            if (strcasecmp($w->text, $text) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param PdfWord[] $words
     */
    private function sortReading(array &$words): void
    {
        usort($words, static fn (PdfWord $a, PdfWord $b) => [$a->y0, $a->x0] <=> [$b->y0, $b->x0]);
    }
}
