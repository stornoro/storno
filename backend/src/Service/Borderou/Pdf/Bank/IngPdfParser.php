<?php

namespace App\Service\Borderou\Pdf\Bank;

use App\Service\Borderou\Pdf\AbstractPdfStatementParser;
use App\Service\Borderou\Pdf\PdfStatement;
use App\Service\Borderou\Pdf\PdfStatementNotRecognizedException;
use App\Service\Borderou\Pdf\PdfStatementTransaction;
use App\Service\Borderou\Pdf\PdfWord;

/**
 * ING Bank Romania, older layout: page 1 carries a "Rezumat"/"Summary" block and a statement
 * number; table "Data / Referinta bancii / Descrierea tranzactiei / Debitari / Creditari / Sold",
 * amounts 1,234.56, dates dd.MM.yyyy.
 */
class IngPdfParser extends AbstractPdfStatementParser
{
    protected const ISO = ['RON', 'EUR', 'USD', 'GBP', 'CHF', 'JPY', 'AUD', 'CAD', 'SEK', 'NOK', 'DKK'];
    protected const LEGAL_FORMS = ['SRL', 'S.R.L.', 'S.R.L', 'SRL-D', 'SA', 'S.A.', 'PFA', 'P.F.A.', 'SNC', 'SCS', 'SCA'];
    protected const BANK_REF_RX = '/\b(?:Referin(?:t|ț|ţ)a\s+b(?:a|ă)ncii|bank\s+reference)\s*[:\-]?\s*(?<val>(?:(?!(?:\bReferin(?:t|ț|ţ)a\b|\b(?:bank|internal)\s+reference\b)).)+)/isu';
    protected const INTERNAL_REF_RX = '/\b(?:Referin(?:t|ț|ţ)a\s+intern(?:a|ă)|internal\s+reference)\s*[:\-]?\s*(?<val>(?:(?!(?:\bReferin(?:t|ț|ţ)a\b|\b(?:bank|internal)\s+reference\b)).)+)/isu';

    public function getBankKey(): string
    {
        return 'ing';
    }

    public function getBankLabel(): string
    {
        return 'ING Bank';
    }

    public function score(array $page1Words): int
    {
        $t = $this->normalise(implode(' ', $page1Words));
        if (!str_contains($t, 'rezumat') && !str_contains($t, 'summary')) {
            return 0;
        }
        $a = str_contains($t, 'ing bank n.v. amsterdam');
        $b = str_contains($t, 'aviator popisteanu');
        $c = str_contains($t, 'numar extras cont') || str_contains($t, 'numar cont') || str_contains($t, 'statement no');
        if ($a && $b && $c) {
            return 100;
        }
        if (str_contains($t, 'www.ing.ro')) {
            return 50;
        }

        return ($a || $b) ? 50 : 0;
    }

    public function parse(array $pages): array
    {
        $page1 = $pages[0];
        $words = $this->wordsInBand($page1, 90.0);
        usort($words, static fn (PdfWord $a, PdfWord $b) => [$a->y0, $a->x0] <=> [$b->y0, $b->x0]);
        $h = $page1->height > 0 ? $page1->height : 842.0;

        $warnings = [];
        $iban = $this->firstWordMatching($words, '/\b[A-Z]{2}\d{2}[A-Z0-9]{10,30}\b/')?->text;
        if ($iban === null) {
            $warnings[] = 'Nu a fost gasit IBAN-ul contului in extras.';
        }

        // Holder box: Left >= 310, Right <= 525, Top <= 750, Bottom >= 725 (PDF coordinates).
        $box = array_values(array_filter($words, static fn (PdfWord $w) => $w->x0 >= 310.0 && $w->x1 <= 525.0 && ($h - $w->y0) <= 750.0 && ($h - $w->y1) >= 725.0));
        usort($box, static fn (PdfWord $a, PdfWord $b) => [$a->y0, $a->x0] <=> [$b->y0, $b->x0]);
        $holder = $this->holderRightOfLabel($box) ?? $this->joinUntilLegalForm(array_map(static fn (PdfWord $w) => $w->text, $box));
        $fiscalCode = $this->digitsRightOfLabel($box, '/^CUI:$/');

        // Currency box: Left >= 30, Right <= 150, Top <= 720, Bottom >= 700.
        $cbox = array_values(array_filter($words, static fn (PdfWord $w) => $w->x0 >= 30.0 && $w->x1 <= 150.0 && ($h - $w->y0) <= 720.0 && ($h - $w->y1) >= 700.0));
        usort($cbox, static fn (PdfWord $a, PdfWord $b) => [$a->x0, $a->y0] <=> [$b->x0, $b->y0]);
        $currency = $this->currencyAfterWord($cbox, 'Valuta:', 15, self::ISO)
            ?? $this->currencyAfterWord($cbox, 'Currency:', 15, self::ISO)
            ?? $this->firstIsoCurrency($cbox, self::ISO)
            ?? 'RON';

        // --- table -------------------------------------------------------
        $lines = $this->linesInBand($pages, 75.0, 750.0);

        // "Rezumat" block: lines 5..14 carry "Sold initial" / "Sold final".
        $opening = null;
        $printedClosing = null;
        for ($i = 5; $i <= 14 && $i < count($lines); $i++) {
            if ($opening === null && ($v = $this->rezumatOpening($lines[$i])) !== null) {
                $opening = $v;
                continue;
            }
            if (($v = $this->rezumatClosing($lines[$i])) !== null) {
                $printedClosing = $v;
                break;
            }
        }

        $headerIdx = null;
        foreach ($lines as $i => $w) {
            if (count($w) < 8) {
                continue;
            }
            $t = array_map(fn (PdfWord $x) => $this->normalise($x->text), $w);
            $ok = ($this->seq($t, ['referinta', 'bancii']) || $this->seq($t, ['bank', 'reference']))
                && ($this->anyStarts($t, 'descrierea') || $this->seq($t, ['transaction', 'description']))
                && (in_array('debitari', $t, true) || in_array('debit', $t, true))
                && (in_array('creditari', $t, true) || in_array('credit', $t, true));
            if ($ok) {
                $headerIdx = $i;
                break;
            }
        }
        if ($headerIdx === null || count($lines[$headerIdx]) !== 8) {
            throw new PdfStatementNotRecognizedException('Nu am putut identifica antetul tabelului (Data / Descriere / Debitari / Creditari) in PDF-ul ING. Este posibil ca formatul extrasului sa fi fost modificat sau ca banca sa nu fie inca suportata.');
        }
        $hw = $lines[$headerIdx];
        $ref = $this->findWord($hw, static fn (string $t) => strcasecmp($t, 'bancii') === 0 || strcasecmp($t, 'Reference') === 0, true);
        $debit = $this->findWord($hw, static fn (string $t) => stripos($t, 'Debit') === 0);
        $credit = $this->findWord($hw, static fn (string $t) => stripos($t, 'Credit') === 0);
        $sold = $this->findWord($hw, static fn (string $t) => strcasecmp($t, 'Sold') === 0 || strcasecmp($t, 'Balance') === 0, true);
        $bounds = [-INF, $ref->x1 + 8.0, $debit->x0 - 16.0, $credit->x0 - 16.0, $sold->x0 - 12.8];

        $result = $this->walkIngTable($lines, $headerIdx + 1, $bounds, $opening);
        $warnings = array_merge($warnings, $result['warnings']);
        if ($mismatch = $this->closingMismatch($printedClosing, $result['running'])) {
            $warnings[] = $mismatch;
        }
        if ($result['transactions'] === []) {
            $warnings[] = 'Nu a fost gasita nicio tranzactie in extras.';
        }

        return [new PdfStatement(
            $this->getBankKey(),
            $this->getBankLabel(),
            $iban,
            $currency,
            $result['transactions'],
            $holder,
            $fiscalCode,
            $opening,
            $printedClosing,
            null,
            null,
            $warnings,
        )];
    }

    // ------------------------------------------------------------------
    // Table walk shared by both ING layouts
    // ------------------------------------------------------------------

    /**
     * A row starts when exactly one of the Debitari/Creditari cells holds a non-zero amount;
     * other rows continue the description (column 1). Column 4 (Sold) is ignored.
     *
     * @param array<int, PdfWord[]> $lines
     * @param float[] $bounds
     * @return array{transactions: PdfStatementTransaction[], running: ?string, warnings: string[]}
     */
    protected function walkIngTable(array $lines, int $startAt, array $bounds, ?string $opening): array
    {
        $transactions = [];
        $warnings = [];
        $running = $opening;
        $current = null;
        $date = null;

        $flush = function () use (&$current, &$transactions): void {
            if ($current === null) {
                return;
            }
            [$desc, $reference] = $this->extractReference($current['description']);
            [$name, $cpIban] = $this->extractCounterparty($desc);
            $transactions[] = new PdfStatementTransaction(
                $current['date'], $desc, $current['debit'], $current['credit'], $reference,
                null, $name, $cpIban, $current['closing'], null, $current['raw'],
            );
            $current = null;
        };

        $n = count($lines);
        for ($i = $startAt; $i < $n; $i++) {
            $c = $this->splitByBoundaries($lines[$i], $bounds);
            $joined = trim(implode(' ', $c));
            $debit = $this->nonZeroMoney($c[2] ?? '');
            $credit = $this->nonZeroMoney($c[3] ?? '');
            $exactlyOne = ($debit !== null) !== ($credit !== null);
            if ($exactlyOne) {
                $flush();
                $dateCell = trim($c[0] ?? '');
                if ($this->parseDateStrict($dateCell) !== null) {
                    $parsed = $this->parseDate($dateCell, ['d.m.Y']);
                    if ($parsed === null) {
                        $warnings[] = 'Data intr-un format neasteptat (se astepta zz.ll.aaaa): ' . $dateCell;
                        $parsed = $this->parseDateStrict($dateCell);
                    }
                    $date = $parsed;
                }
                if ($date === null) {
                    $warnings[] = 'Rand cu suma fara data: ' . $joined;
                    continue;
                }
                $isCredit = $credit !== null;
                $amount = $this->bcAbs($isCredit ? $credit : $debit);
                $open = $running ?? '0.00';
                $closing = $isCredit ? bcadd($open, $amount, 2) : bcsub($open, $amount, 2);
                $running = $closing;
                $current = [
                    'date' => $date,
                    'description' => trim($c[1] ?? ''),
                    'debit' => $isCredit ? '0.00' : $amount,
                    'credit' => $isCredit ? $amount : '0.00',
                    'closing' => $closing,
                    'raw' => [$joined],
                ];
                continue;
            }
            if ($current !== null) {
                if (trim($c[1] ?? '') !== '') {
                    $current['description'] .= ' ' . trim($c[1]);
                }
                $current['raw'][] = $joined;
            }
        }
        $flush();

        return ['transactions' => $transactions, 'running' => $running, 'warnings' => $warnings];
    }

    protected function nonZeroMoney(string $cell): ?string
    {
        $v = $this->parseMoneyEn($cell);
        if ($v === null || bccomp($v, '0', 2) === 0) {
            return null;
        }

        return $v;
    }

    /**
     * "Referinta bancii: X" (preferred) or "Referinta interna: X" is cut out of the description
     * and returned (whitespace removed) as the reference.
     *
     * @return array{0: string, 1: ?string}
     */
    protected function extractReference(string $description): array
    {
        $reference = null;
        foreach ([self::BANK_REF_RX, self::INTERNAL_REF_RX] as $rx) {
            if (preg_match($rx, $description, $m)) {
                $reference = preg_replace('/\s+/u', '', $m['val']) ?? $m['val'];
                $description = str_replace($m[0], '', $description);
                break;
            }
        }
        $description = trim($description);
        $description = preg_replace('/[ \t]{2,}/', ' ', $description) ?? $description;
        $description = preg_replace('/\r?\n\s*\r?\n/', "\r\n", $description) ?? $description;

        return [$description, $reference !== null && $reference !== '' ? $reference : null];
    }

    /**
     * Counterparty name after "Beneficiar:"/"Ordonator:" and IBAN after "Contul:"/"In contul:"/"Din contul:".
     *
     * @return array{0: ?string, 1: ?string}
     */
    protected function extractCounterparty(string $description): array
    {
        $name = null;
        $iban = null;
        if (preg_match('/\b(?:Beneficiar|Ordonator|Platitor)\s*:\s*(.+?)(?=\s+\b[A-Za-z][A-Za-z ]{1,25}:|$)/iu', $description, $m)) {
            $name = trim($m[1]) ?: null;
        }
        if (preg_match('/\b(?:In contul|Din contul|Contul|Cont)\s*:\s*([A-Z]{2}\d{2}[A-Z0-9 ]{10,40})/iu', $description, $m)) {
            $iban = $this->extractIban($m[1]);
        }

        return [$name, $iban];
    }

    // ------------------------------------------------------------------
    // Header helpers
    // ------------------------------------------------------------------

    /**
     * Holder printed after "Titular cont:" / "Account owner:" on the same line (or on the next
     * non-empty line). Label tokens (CUI/CIF/CNP/IBAN) end the name.
     *
     * @param PdfWord[] $box
     */
    protected function holderRightOfLabel(array $box): ?string
    {
        $lines = $this->clusterer->cluster($box, 1.5);
        foreach ($lines as $li => $line) {
            $t = array_map(fn (PdfWord $w) => trim(trim($this->normalise($w->text)), ':'), $line);
            $n = count($t);
            for ($i = 0; $i + 1 < $n; $i++) {
                $pair = ($t[$i] === 'titular' && str_starts_with($t[$i + 1], 'cont'))
                    || ($t[$i] === 'account' && str_starts_with($t[$i + 1], 'owner'));
                if (!$pair) {
                    continue;
                }
                $start = $i + 2;
                while ($start < $n && trim($t[$start], ":-\u{2013}\u{2014}") === '') {
                    $start++;
                }
                if ($start < $n) {
                    $parts = [];
                    for ($k = $start; $k < $n; $k++) {
                        if (in_array($t[$k], ['cui', 'cif', 'cnp', 'iban'], true)) {
                            break;
                        }
                        $s = trim(trim($line[$k]->text), ':');
                        if ($s !== '') {
                            $parts[] = $s;
                        }
                    }
                    if ($parts !== []) {
                        return implode(' ', $parts);
                    }
                }
                for ($j = $li + 1; $j < count($lines); $j++) {
                    $parts = [];
                    foreach ($lines[$j] as $w) {
                        $s = trim(trim($w->text), ':');
                        if ($s !== '') {
                            $parts[] = $s;
                        }
                    }
                    if ($parts !== []) {
                        return implode(' ', $parts);
                    }
                }

                return null;
            }
        }

        return null;
    }

    /**
     * @param string[] $tokens
     */
    protected function joinUntilLegalForm(array $tokens): ?string
    {
        $parts = [];
        foreach ($tokens as $tok) {
            $tok = trim($tok);
            if ($tok === '') {
                continue;
            }
            $parts[] = $tok;
            foreach (self::LEGAL_FORMS as $lf) {
                if (strcasecmp($tok, $lf) === 0) {
                    return implode(' ', $parts);
                }
            }
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    /**
     * Digits printed to the right of a label word on the same baseline.
     *
     * @param PdfWord[] $words
     */
    protected function digitsRightOfLabel(array $words, string $labelRegex): ?string
    {
        $label = $this->firstWordMatching($words, $labelRegex);
        if (!$label) {
            return null;
        }
        $candidates = array_filter($words, static fn (PdfWord $w) => abs($w->y1 - $label->y1) < 0.8 && $w->x0 > $label->x1);
        usort($candidates, static fn (PdfWord $a, PdfWord $b) => $a->x0 <=> $b->x0);
        foreach ($candidates as $w) {
            $t = trim($w->text, ':');
            if (preg_match('/^\d+$/', $t)) {
                return $t;
            }
        }

        return null;
    }

    /**
     * @param PdfWord[] $line
     */
    private function rezumatOpening(array $line): ?string
    {
        $text = $this->normalise($this->lineText($line));
        $money = $this->rightmostMoney($line);
        if ($money === null) {
            return null;
        }
        if ((str_contains($text, 'sold') && str_contains($text, 'initial')) || str_contains($text, 'opening balance')) {
            return $money;
        }
        if (str_contains($text, 'sold') || str_contains($text, 'initial')) {
            return $money;
        }

        return null;
    }

    /**
     * @param PdfWord[] $line
     */
    private function rezumatClosing(array $line): ?string
    {
        $money = $this->rightmostMoney($line);
        if ($money !== null && $this->hasAnyKnownToken($line, ['Sold final', 'Closing balance'])) {
            return $money;
        }
        if (count($line) > 4 && $this->parseMoneyEn($line[4]->text) !== null && trim($line[0]->text) !== '' && trim($line[1]->text) !== '') {
            return $money ?? $this->parseMoneyEn($line[4]->text);
        }
        $text = $this->normalise($this->lineText($line));
        if ($money !== null && (str_contains($text, 'sold') || str_contains($text, 'final') || str_contains($text, 'closing'))) {
            return $money;
        }

        return null;
    }

    /**
     * @param PdfWord[] $line
     * @param string[] $labels
     */
    private function hasAnyKnownToken(array $line, array $labels): bool
    {
        $text = $this->normalise($this->lineText($line));
        $tokens = [];
        foreach ($line as $w) {
            $t = trim($this->normalise($w->text), ":-\u{2013}\u{2014}");
            if ($t !== '') {
                $tokens[] = $t;
            }
        }
        foreach ($labels as $label) {
            $l = $this->normalise($label);
            if (str_contains($text, $l)) {
                return true;
            }
            $need = preg_split('/\s+/', $l) ?: [];
            $p = 0;
            foreach ($tokens as $tok) {
                if ($p < count($need) && ($tok === $need[$p] || rtrim($tok, ':') === $need[$p])) {
                    $p++;
                }
            }
            if ($p === count($need)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param PdfWord[] $line
     */
    private function rightmostMoney(array $line): ?string
    {
        for ($i = count($line) - 1; $i >= 0; $i--) {
            $v = $this->parseMoneyEn(trim($line[$i]->text));
            if ($v !== null) {
                return $v;
            }
        }

        return null;
    }

    /**
     * @param string[] $tokens
     * @param string[] $needle
     */
    protected function seq(array $tokens, array $needle): bool
    {
        $n = count($needle);
        for ($i = 0; $i + $n <= count($tokens); $i++) {
            if (array_slice($tokens, $i, $n) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $tokens
     */
    protected function anyStarts(array $tokens, string ...$prefixes): bool
    {
        foreach ($tokens as $t) {
            foreach ($prefixes as $p) {
                if (str_starts_with($t, $p)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param PdfWord[] $words
     * @param callable(string): bool $match
     */
    protected function findWord(array $words, callable $match, bool $last = false): PdfWord
    {
        $found = null;
        foreach ($words as $w) {
            if ($match($w->text)) {
                $found = $w;
                if (!$last) {
                    break;
                }
            }
        }
        if (!$found) {
            throw new PdfStatementNotRecognizedException('Antetul tabelului ING este incomplet.');
        }

        return $found;
    }
}
