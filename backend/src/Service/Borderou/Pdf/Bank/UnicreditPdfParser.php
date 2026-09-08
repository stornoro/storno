<?php

namespace App\Service\Borderou\Pdf\Bank;

use App\Service\Borderou\Pdf\AbstractPdfStatementParser;
use App\Service\Borderou\Pdf\PdfStatement;
use App\Service\Borderou\Pdf\PdfStatementNotRecognizedException;
use App\Service\Borderou\Pdf\PdfStatementTransaction;
use App\Service\Borderou\Pdf\PdfWord;

/**
 * UniCredit Bank statement without a running-balance column: sections "Sumar cont"
 * (Sold initial / sume debitate / sume creditate / Sold final) and "Tranzactii"
 * (Data / Descriere / Debit / Credit), dates "dd MMMM yyyy" in Romanian, amounts 1,234.56.
 *
 * Bold text in these PDFs is often extracted with doubled glyphs ("DDaattaa"), so every
 * label comparison goes through dedup(). Font information is not available from our
 * extractor, so the "bold description = transaction headline" signal of the original
 * heuristic is replaced by "the line carries a date and an amount" (see isTransactionStart()).
 */
class UnicreditPdfParser extends AbstractPdfStatementParser
{
    protected const ISO = ['RON', 'EUR', 'USD', 'GBP', 'CHF', 'JPY', 'AUD', 'CAD', 'SEK', 'NOK', 'DKK', 'HUF', 'PLN'];
    protected const REF_REGEX = '/(?<!\d)\d{10,12}(?!\d)/';
    protected const START_KEYWORDS = ['plata', 'incasare', 'schimb', 'transfer', 'com', 'taxa'];

    public function getBankKey(): string
    {
        return 'unicredit';
    }

    public function getBankLabel(): string
    {
        return 'UniCredit Bank';
    }

    public function score(array $page1Words): int
    {
        [$a, $b, $c, $d] = $this->detectionFlags($page1Words);
        if ($a && $b && $c && !$d) {
            return 100;
        }

        return ($a || $b || $c) ? 50 : 0;
    }

    /**
     * @param string[] $page1Words
     * @return array{0: bool, 1: bool, 2: bool, 3: bool}
     */
    protected function detectionFlags(array $page1Words): array
    {
        $t = $this->normalise($this->dedup(implode(' ', $page1Words)));

        return [
            str_contains($t, 'unicredit bank s.a.'),
            str_contains($t, 'bulevardul expozitiei nr. 1f'),
            str_contains($t, 'unicredit.ro'),
            str_contains($t, 'sold('),
        ];
    }

    // ------------------------------------------------------------------
    // Version hooks
    // ------------------------------------------------------------------

    protected function headerWordCount(): int
    {
        return 4;
    }

    protected function lineTolerance(): float
    {
        return 2.5;
    }

    protected function boldWeight(): int
    {
        return 3;
    }

    /**
     * @param PdfWord[] $header
     */
    protected function isHeaderLine(array $header): bool
    {
        if (count($header) !== $this->headerWordCount()) {
            return false;
        }
        $d = array_map(fn (PdfWord $w) => $this->normalise($this->dedup($w->text)), $header);

        return $d[0] === 'data' && str_starts_with($d[1], 'descriere') && in_array('debit', $d, true) && in_array('credit', $d, true);
    }

    /**
     * @param PdfWord[] $header
     * @return float[]
     */
    protected function boundaries(array $header): array
    {
        [$descr, $debit, $credit] = $this->headerAnchors($header);

        return [-INF, $descr->x0, $debit->x0 - 40.0, $credit->x0 - 40.0];
    }

    /**
     * @param PdfWord[] $header
     * @return array{0: PdfWord, 1: PdfWord, 2: PdfWord} last "Descriere", first "Debit", first "Credit"
     */
    protected function headerAnchors(array $header): array
    {
        $descr = $debit = $credit = null;
        foreach ($header as $w) {
            $d = $this->normalise($this->dedup($w->text));
            if ($d === 'descriere') {
                $descr = $w;
            } elseif ($d === 'debit') {
                $debit ??= $w;
            } elseif ($d === 'credit') {
                $credit ??= $w;
            }
        }
        if (!$descr || !$debit || !$credit) {
            throw new PdfStatementNotRecognizedException('Antetul tabelului UniCredit este incomplet.');
        }

        return [$descr, $debit, $credit];
    }

    /**
     * V1 state machine: a start opens a transaction unless the previous line was already a
     * start with no amount since; otherwise the first line with text/amount opens one, and a
     * "bold" line after a transaction that already holds an amount opens a new one.
     *
     * @param array{prevWasStart: bool, amountSeen: bool} $state
     * @param array<string, mixed>|null $current
     */
    protected function startsNewTransaction(bool $isStart, bool $bold, array &$state, ?array $current, string $desc, bool $hasAmount): bool
    {
        $new = $isStart && (!$state['prevWasStart'] || $state['amountSeen']);
        if (!$new) {
            $new = ($current === null && ($desc !== '' || $hasAmount))
                || ($bold && $current !== null && bccomp($current['amount'], '0', 2) > 0);
            if ($new) {
                $state['amountSeen'] = false;
            }
        }

        return $new;
    }

    protected function headerNotFoundMessage(): string
    {
        return 'Nu am putut identifica antetul tabelului (Data / Descriere / Debit / Credit) in PDF-ul UniCredit. Este posibil ca formatul extrasului sa fi fost modificat.';
    }

    // ------------------------------------------------------------------

    public function parse(array $pages): array
    {
        $page1 = $pages[0];
        $h = $page1->height > 0 ? $page1->height : 842.0;
        $r = $this->wordsInBand($page1, 90.0);
        $this->sortReading($r);

        $iban = $this->cleanIban($this->afterLabel($r, 'IBAN')) ?? $this->findIban($this->clusterer->cluster($r, 1.5), 'IBAN');
        $upper = array_values(array_filter($r, static fn (PdfWord $w) => $w->x0 < 390.0 && $w->y0 < $h - 700.0));
        $holder = $this->afterLabel($upper, 'Denumire', 'companie') ?? $this->holderAfterCompanie($upper);
        $currency = $this->currencyAfterLabel($r, 'moneda:') ?? $this->firstIsoCurrency($page1->words, self::ISO) ?? 'RON';

        // --- sections ------------------------------------------------------
        $lines = [];
        $id = 0;
        foreach ($pages as $page) {
            foreach ($this->clusterer->cluster($this->wordsInBand($page, 80.0, 750.0), $this->lineTolerance()) as $line) {
                $lines[] = ['page' => $page->number, 'words' => $line, 'id' => $id++];
            }
        }
        $s = $this->filterSections($lines, ['tranzactii', 'sumar cont'], true);

        $header = null;
        foreach ($s as $line) {
            if ($this->isHeaderLine($line['words'])) {
                $header = $line;
                break;
            }
        }
        if ($header === null) {
            throw new PdfStatementNotRecognizedException($this->headerNotFoundMessage());
        }
        $bounds = $this->boundaries($header['words']);
        [$opening, $printedClosing] = $this->summaryBalances($s);

        // --- rows ----------------------------------------------------------
        $t = $this->filterSections($s, ['tranzactii'], false);
        $transactions = [];
        $warnings = [];
        $running = $opening;
        $current = null;
        $state = ['prevWasStart' => false, 'amountSeen' => false];
        $started = false;

        $flush = function () use (&$current, &$transactions, &$warnings): void {
            if ($current === null) {
                return;
            }
            $desc = trim(preg_replace('/\s{2,}/', ' ', $current['description']) ?? '');
            $hasAmount = bccomp($current['amount'], '0', 2) > 0;
            if ($current['date'] === null || !$hasAmount) {
                if ($hasAmount) {
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
                $this->longestReference($desc),
                null,
                null,
                null,
                $current['closing'],
                null,
                $current['raw'],
            );
            $current = null;
        };

        foreach ($t as $line) {
            if (!$started) {
                if ($line['id'] === $header['id']) {
                    $started = true;
                }
                continue;
            }
            $cells = $this->splitByBoundaries($line['words'], $bounds);
            if ($this->isTableHeaderCells($cells)) {
                continue;
            }
            $joined = trim(implode(' ', $cells));
            $desc = trim($cells[1] ?? '');
            $date = $this->parseDateStrict($cells[0] ?? '');
            $debit = $this->nonZeroMoney($cells[2] ?? '');
            $credit = $this->nonZeroMoney($cells[3] ?? '');
            $hasAmount = $debit !== null || $credit !== null;
            $bold = $date !== null && $hasAmount; // stand-in for the bold-font signal
            $isStart = $this->isTransactionStart($desc, $bold) || ($bold && $desc !== '');

            $newTx = $this->startsNewTransaction($isStart, $bold, $state, $current, $desc, $hasAmount);
            if ($isStart) {
                $state['prevWasStart'] = true;
                $state['amountSeen'] = false;
            } else {
                $state['prevWasStart'] = false;
            }
            if ($hasAmount) {
                $state['amountSeen'] = true;
            }

            if ($newTx) {
                $flush();
                $current = ['description' => $desc, 'date' => null, 'amount' => '0.00', 'credit' => false, 'closing' => null, 'raw' => []];
            } elseif ($current !== null && $desc !== '') {
                $current['description'] .= ' ' . $desc;
            }
            if ($current !== null) {
                $current['raw'][] = $joined;
                if ($date !== null && $hasAmount) {
                    $isCredit = $credit !== null;
                    $amount = $this->bcAbs($isCredit ? $credit : $debit);
                    $open = $running ?? '0.00';
                    $closing = $isCredit ? bcadd($open, $amount, 2) : bcsub($open, $amount, 2);
                    $running = $closing;
                    $current['date'] = $date;
                    $current['amount'] = $amount;
                    $current['credit'] = $isCredit;
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
     * Original heuristic: +bold, +3 leading "+", +3 leading digit (unless a date), +2 mostly
     * upper-case, +3 keyword, +1 short; start iff (bold | "+" | digit) and score >= 7.
     */
    protected function isTransactionStart(string $text, bool $bold): bool
    {
        $t = $this->normalizePdfText($text);
        if ($t === '') {
            return false;
        }
        $lower = mb_strtolower($t);
        $first = mb_substr($t, 0, 1);
        $plus = $first === '+';
        $digit = ctype_digit($first);
        $score = $bold ? $this->boldWeight() : 0;
        if ($plus) {
            $score += 3;
        }
        if ($digit) {
            $head = implode(' ', array_slice(preg_split('/\s+/', $t) ?: [], 0, 3));
            if ($this->parseDateStrict($head) === null) {
                $score += 3;
            }
        }
        $letters = preg_match_all('/\p{L}/u', $t);
        $upper = preg_match_all('/\p{Lu}/u', $t);
        if ($letters > 0 && $upper > 0.6 * $letters) {
            $score += 2;
        }
        foreach (static::START_KEYWORDS as $kw) {
            if (str_starts_with($lower, $kw)) {
                $score += 3;
                break;
            }
        }
        if (mb_strlen($t) < 60) {
            $score += 1;
        }

        return ($bold || $plus || $digit) && $score >= 7;
    }

    /**
     * @param string[] $cells
     */
    protected function isTableHeaderCells(array $cells): bool
    {
        if (count($cells) < 4) {
            return false;
        }
        $d = array_map(fn (string $c) => $this->normalise($this->dedup(trim($c))), $cells);
        $debit = $credit = false;
        foreach ($d as $c) {
            if (str_contains($c, 'debit')) {
                $debit = true;
            }
            if (str_contains($c, 'credit')) {
                $credit = true;
            }
        }

        return str_starts_with($d[0], 'data') && str_starts_with($d[1], 'descriere') && $debit && $credit;
    }

    protected function nonZeroMoney(string $v): ?string
    {
        $m = $this->parseMoneyEn($v);

        return ($m === null || bccomp($m, '0', 2) === 0) ? null : $m;
    }

    protected function longestReference(string $desc): ?string
    {
        if (!preg_match_all(static::REF_REGEX, $desc, $m)) {
            return null;
        }
        $best = null;
        foreach ($m[0] as $ref) {
            if ($best === null || strlen($ref) > strlen($best)) {
                $best = $ref;
            }
        }

        return $best;
    }

    // ------------------------------------------------------------------
    // Sections and balances
    // ------------------------------------------------------------------

    /**
     * Keeps the lines that follow a section title ("Tranzactii", "Sumar cont") until a footer
     * phrase; the collecting state resets on every page.
     *
     * @param array<int, array{page: int, words: PdfWord[], id: int}> $lines
     * @param string[] $startPhrases
     * @return array<int, array{page: int, words: PdfWord[], id: int}>
     */
    protected function filterSections(array $lines, array $startPhrases, bool $keepStart): array
    {
        $out = [];
        $collecting = false;
        $page = null;
        foreach ($lines as $line) {
            if ($line['page'] !== $page) {
                $collecting = false;
                $page = $line['page'];
            }
            $n = $this->dedup($this->normalise($this->normalizePdfText($this->lineText($line['words']))));
            if (!$collecting) {
                foreach ($startPhrases as $phrase) {
                    if ($n !== '' && str_contains($phrase, $n)) {
                        $collecting = true;
                        if ($keepStart) {
                            $out[] = $line;
                        }
                        break;
                    }
                }
                continue;
            }
            if (str_contains($n, 'fondurile disponibile') || str_contains($n, 'nume, pozitie')) {
                $collecting = false;
                continue;
            }
            $out[] = $line;
        }

        return $out;
    }

    /**
     * "Sold initial sume debitate sume creditate Sold final" + amounts: first amount after the
     * first such header = opening, last amount after the last such header = closing.
     *
     * @param array<int, array{page: int, words: PdfWord[], id: int}> $lines
     * @return array{0: ?string, 1: ?string}
     */
    protected function summaryBalances(array $lines): array
    {
        $opening = null;
        $closing = null;
        $count = count($lines);
        for ($k = 0; $k < $count - 1; $k++) {
            $w = $lines[$k]['words'];
            if (count($w) !== 8) {
                continue;
            }
            $d = array_map(fn (PdfWord $x) => $this->normalise($this->dedup($this->normalizePdfText($x->text))), $w);
            if ($d[0] === 'sold' && str_starts_with($d[2], 'sume') && in_array('debitate', $d, true) && in_array('creditate', $d, true) && str_starts_with($d[7], 'final')) {
                $next = $lines[$k + 1]['words'];
                if ($opening === null) {
                    $opening = $this->parseMoneyEn($next[0]->text);
                }
                $closing = $this->parseMoneyEn($next[count($next) - 1]->text) ?? $closing;
            }
        }

        return [$opening, $closing];
    }

    // ------------------------------------------------------------------
    // Metadata helpers
    // ------------------------------------------------------------------

    /**
     * Texts following "$first [$second]…" on a >= 3-word line, space-joined.
     *
     * @param PdfWord[] $words
     */
    protected function afterLabel(array $words, string $first, string $second = ''): ?string
    {
        foreach ($this->clusterer->cluster($words, 1.5) as $line) {
            $n = count($line);
            if ($n < 3) {
                continue;
            }
            for ($i = 0; $i < $n; $i++) {
                if (stripos($line[$i]->text, $first) !== 0) {
                    continue;
                }
                $labelEnd = $i;
                if ($second !== '') {
                    if (!isset($line[$i + 1]) || stripos($line[$i + 1]->text, $second) !== 0) {
                        continue;
                    }
                    $labelEnd = $i + 1;
                }
                $rest = array_slice($line, $labelEnd + 1);
                if ($rest === []) {
                    continue;
                }
                $value = trim(implode(' ', array_map(static fn (PdfWord $w) => $w->text, $rest)));

                return $value === '' ? null : $value;
            }
        }

        return null;
    }

    protected function cleanIban(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $v = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '');

        return strlen($v) === 24 ? $v : null;
    }

    /**
     * @param PdfWord[] $words
     */
    protected function holderAfterCompanie(array $words): ?string
    {
        $texts = array_map(static fn (PdfWord $w) => $w->text, $words);
        $start = null;
        foreach ($texts as $i => $t) {
            if (strcasecmp($t, 'companie:') === 0) {
                $start = $i + 1;
                break;
            }
        }
        if ($start === null) {
            return null;
        }
        $parts = [];
        for ($i = $start; $i < count($texts); $i++) {
            if (strcasecmp($texts[$i], 'SRL') === 0) {
                break;
            }
            $parts[] = $texts[$i];
        }
        $parts[] = 'SRL';
        $name = trim(implode(' ', $parts));

        return $name === 'SRL' ? null : $name;
    }

    /**
     * @param PdfWord[] $words
     */
    protected function currencyAfterLabel(array $words, string $label): ?string
    {
        $flat = [];
        foreach ($this->clusterer->cluster($words, 2.5) as $line) {
            foreach ($line as $w) {
                $flat[] = $w->text;
            }
        }
        $n = count($flat);
        for ($i = 0; $i < $n; $i++) {
            if (strcasecmp($flat[$i], $label) !== 0) {
                continue;
            }
            for ($j = $i + 1; $j < $n; $j++) {
                $t = rtrim(trim($flat[$j]), ':;.,');
                if ($t === '') {
                    continue;
                }

                return strcasecmp($t, 'LEI') === 0 ? 'RON' : strtoupper($t);
            }
        }

        return null;
    }

    /**
     * Collapses runs of identical characters ("DDaattaa" => "Data").
     */
    protected function dedup(string $s): string
    {
        return preg_replace('/(.)\1+/us', '$1', $s) ?? $s;
    }

    protected function normalizePdfText(string $s): string
    {
        return trim(str_replace(["\u{FB01}", "\u{FB02}", "\u{00A0}", "\u{200B}"], ['fi', 'fl', ' ', ''], $s));
    }

    /**
     * @param PdfWord[] $words
     */
    protected function sortReading(array &$words): void
    {
        usort($words, static fn (PdfWord $a, PdfWord $b) => [$a->y0, $a->x0] <=> [$b->y0, $b->x0]);
    }
}
