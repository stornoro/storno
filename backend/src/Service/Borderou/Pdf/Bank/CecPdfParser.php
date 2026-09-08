<?php

namespace App\Service\Borderou\Pdf\Bank;

use App\Service\Borderou\Pdf\AbstractPdfStatementParser;
use App\Service\Borderou\Pdf\PdfStatement;
use App\Service\Borderou\Pdf\PdfStatementNotRecognizedException;
use App\Service\Borderou\Pdf\PdfStatementTransaction;
use App\Service\Borderou\Pdf\PdfWord;

/**
 * CEC Bank "classic" layout: "Titular cont:" label, table "Nr / Data / Detalii / Numar ordin client / Suma"
 * with a single signed amount column, amounts 1.234,56, dates dd.MM.yyyy.
 */
class CecPdfParser extends AbstractPdfStatementParser
{
    private const ISO = ['RON', 'EUR', 'USD', 'GBP', 'CHF', 'JPY', 'AUD', 'CAD', 'SEK', 'NOK', 'DKK'];
    private const LEGAL_FORMS = ['SRL', 'S.R.L.', 'S.R.L', 'SRL-D', 'SA', 'S.A.', 'PFA', 'P.F.A.', 'SNC', 'SCS', 'SCA'];
    private const IBAN_RX = '/\b[A-Z]{2}\d{2}[A-Z0-9]{10,30}\b/';
    private const MONEY_RX = '/[-+]?\d{1,3}(\.\d{3})*,\d{2}|\d+,\d{2}/';

    public function getBankKey(): string
    {
        return 'cec';
    }

    public function getBankLabel(): string
    {
        return 'CEC Bank';
    }

    public function score(array $page1Words): int
    {
        $t = $this->normalise(implode(' ', $page1Words));
        $a = str_contains($t, 'cec bank');
        $b = str_contains($t, 'www.ceconline.ro');
        $c = str_contains($t, 'www.cec.ro');
        $d = str_contains($t, 'titular cont');
        if ($a && $c && $d) {
            return 100;
        }
        if ($a || $c) {
            return 50;
        }

        return $b ? 50 : 0;
    }

    public function parse(array $pages): array
    {
        $page1 = $pages[0];
        $words = $this->wordsInBand($page1, 90.0);
        usort($words, static fn (PdfWord $a, PdfWord $b) => [$a->y0, $a->x0] <=> [$b->y0, $b->x0]);
        $warnings = [];

        $iban = $this->firstWordMatching($words, self::IBAN_RX)?->text;
        if ($iban === null) {
            $warnings[] = 'Nu a fost gasit IBAN-ul contului in extras.';
        }
        $headerLines = $this->clusterer->cluster($words, 1.5);
        $holder = $this->holderAfterTitularCont($headerLines) ?? $this->holderAfterIban($words);
        $currency = $this->currencyAfterValutaExtras($headerLines)
            ?? $this->firstIsoCurrency($page1->words, self::ISO)
            ?? 'RON';

        $bandWords = $this->wordsInBand($page1, 75.0, 750.0);
        $opening = $this->soldAmount($bandWords, 'initial');
        $printedClosing = $this->soldAmount($bandWords, 'final');

        // --- table -------------------------------------------------------
        $lines = $this->linesInBand($pages, 75.0, 750.0);
        $headerIdx = null;
        foreach ($lines as $i => $w) {
            if (count($w) < 7) {
                continue;
            }
            $t = array_map(fn (PdfWord $x) => $this->normalise($x->text), $w);
            $ok = in_array('data', $t, true)
                && $this->anyStarts($t, 'detalii')
                && $this->seq($t, ['numar', 'ordin', 'client'])
                && in_array('suma', $t, true);
            if ($ok) {
                $headerIdx = $i;
                break;
            }
        }
        if ($headerIdx === null || count($lines[$headerIdx]) !== 7) {
            throw new PdfStatementNotRecognizedException('Nu am putut identifica antetul tabelului (Data / Detalii / Numar ordin client / Suma) in PDF-ul CEC Bank. Este posibil ca formatul extrasului sa fi fost modificat sau ca banca sa nu fie inca suportata.');
        }
        $hw = $lines[$headerIdx];
        $nr = $this->findWord($hw, static fn (string $t) => stripos($t, 'Nr') === 0);
        $detalii = $this->findWord($hw, static fn (string $t) => stripos($t, 'Detalii') === 0);
        $numar = $this->findWord($hw, static fn (string $t) => stripos($t, 'Numar') === 0);
        $suma = $this->findWord($hw, static fn (string $t) => strcasecmp($t, 'Suma') === 0, true);

        // Amount column: left edge of the rightmost money-looking word, sampled on up to 8 rows.
        $samples = [];
        for ($i = $headerIdx + 3; $i < count($lines) && count($samples) < 8; $i++) {
            $best = null;
            foreach ($lines[$i] as $w) {
                if (preg_match(self::MONEY_RX, $w->text) && ($best === null || $w->x1 > $best->x1)) {
                    $best = $w;
                }
            }
            if ($best !== null) {
                $samples[] = $best->x0;
            }
        }
        $b4 = $samples !== [] ? min($samples) - 4.0 : $suma->x0 - 24.0;
        $bounds = [-INF, $nr->x1 + 8.0, $detalii->x0 - 16.0, $numar->x0 - 16.0, $b4];

        $transactions = [];
        $running = $opening;
        $current = null;
        $date = null;
        $flush = function () use (&$current, &$transactions): void {
            if ($current === null) {
                return;
            }
            $desc = trim(preg_replace('/\s{2,}/', ' ', $current['description']) ?? $current['description']);
            $transactions[] = new PdfStatementTransaction(
                $current['date'], $desc, $current['debit'], $current['credit'], $current['reference'],
                null, $this->counterpartyFrom($desc), null, $current['closing'], null, $current['raw'],
            );
            $current = null;
        };

        for ($i = $headerIdx + 3; $i < count($lines); $i++) {
            $c = $this->splitByBoundaries($lines[$i], $bounds);
            $joined = trim(implode(' ', $c));
            $v = $this->parseMoneyRo($c[4] ?? '');
            if ($v !== null && bccomp($v, '0', 2) !== 0) {
                $flush();
                $dateCell = trim($c[1] ?? '');
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
                $isCredit = bccomp($v, '0', 2) > 0;
                $amount = $this->bcAbs($v);
                $open = $running ?? '0.00';
                $closing = bcadd($open, $v, 2);
                $running = $closing;
                $current = [
                    'date' => $date,
                    'description' => trim($c[2] ?? ''),
                    'reference' => null,
                    'debit' => $isCredit ? '0.00' : $amount,
                    'credit' => $isCredit ? $amount : '0.00',
                    'closing' => $closing,
                    'raw' => [$joined],
                ];
                continue;
            }
            if ($current !== null) {
                $t = trim($c[2] ?? '');
                if ($t !== '') {
                    // The bank wraps long descriptions mid-word: continuation text is glued on
                    // without a separator, except for the "Ordonator" line.
                    if (str_starts_with(strtolower($t), 'ordonator')) {
                        $t = ' ' . $t;
                    }
                    $current['description'] .= $t;
                }
                $r = trim($c[3] ?? '');
                if ($r !== '') {
                    $current['reference'] = $r;
                }
                $current['raw'][] = $joined;
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

    private function counterpartyFrom(string $description): ?string
    {
        if (preg_match('/\b(?:Ordonator|Beneficiar)\s*:\s*(.+?)(?=\s+\b[A-Za-z][A-Za-z ]{1,25}:|$)/iu', $description, $m)) {
            return trim($m[1]) ?: null;
        }

        return null;
    }

    /**
     * "Titular cont: NAME [IBAN: ...]" (or "Account owner:"), name on the label line or the next one.
     *
     * @param array<int, PdfWord[]> $lines
     */
    private function holderAfterTitularCont(array $lines): ?string
    {
        foreach ($lines as $li => $line) {
            $t = array_map(fn (PdfWord $w) => trim($this->normalise($w->text), ':'), $line);
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
                        if (stripos($line[$k]->text, 'IBAN') === 0) {
                            break;
                        }
                        $s = trim($line[$k]->text, ':');
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
                        $s = trim($w->text, ':');
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
     * Fallback: tokens after the IBAN up to (and including) the first legal-form token.
     *
     * @param PdfWord[] $words
     */
    private function holderAfterIban(array $words): ?string
    {
        $tokens = [];
        $seenIban = false;
        foreach ($words as $w) {
            $s = trim($w->text);
            if ($s === '') {
                continue;
            }
            if (!$seenIban) {
                if (preg_match(self::IBAN_RX, $s)) {
                    $seenIban = true;
                }
                continue;
            }
            $tokens[] = $s;
            foreach (self::LEGAL_FORMS as $lf) {
                if (strcasecmp($s, $lf) === 0) {
                    return implode(' ', $tokens);
                }
            }
        }

        return $tokens === [] ? null : implode(' ', $tokens);
    }

    /**
     * "Valuta extras: RON".
     *
     * @param array<int, PdfWord[]> $lines
     */
    private function currencyAfterValutaExtras(array $lines): ?string
    {
        foreach ($lines as $line) {
            $t = array_map(fn (PdfWord $w) => trim($this->normalise($w->text), ":-\u{2013}\u{2014}"), $line);
            $n = count($t);
            for ($i = 0; $i + 1 < $n; $i++) {
                if ($t[$i] !== 'valuta' || !str_starts_with($t[$i + 1], 'extras')) {
                    continue;
                }
                for ($k = $i + 2; $k < $n; $k++) {
                    $s = strtoupper(trim($line[$k]->text, ":-\u{2013}\u{2014}"));
                    if (in_array($s, self::ISO, true)) {
                        return $s;
                    }
                }

                return null;
            }
        }

        return null;
    }

    /**
     * "Sold initial: 1.234,56" / "Sold final: 1.234,56" from the page-1 tokens in extraction order.
     * Date-looking tokens between the label and the amount are skipped.
     *
     * @param PdfWord[] $words
     */
    private function soldAmount(array $words, string $label): ?string
    {
        $tokens = array_map(fn (PdfWord $w) => trim($this->normalise($w->text), ":-\u{2013}\u{2014}"), $words);
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            if ($tokens[$i] !== 'sold') {
                continue;
            }
            for ($j = $i + 1; $j <= min($n - 1, $i + 3); $j++) {
                if (!str_starts_with($tokens[$j], $label)) {
                    continue;
                }
                for ($k = $j + 1; $k < $n; $k++) {
                    $raw = $words[$k]->text;
                    if ($this->parseDateStrict($raw) !== null) {
                        continue;
                    }
                    $m = $this->parseMoneyRo(str_replace(["\u{2212}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{00AD}"], '-', $raw));
                    if ($m !== null) {
                        return $m;
                    }
                }

                return null;
            }
        }

        return null;
    }

    /**
     * @param string[] $tokens
     * @param string[] $needle
     */
    private function seq(array $tokens, array $needle): bool
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
    private function anyStarts(array $tokens, string ...$prefixes): bool
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
    private function findWord(array $words, callable $match, bool $last = false): PdfWord
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
            throw new PdfStatementNotRecognizedException('Antetul tabelului CEC Bank este incomplet.');
        }

        return $found;
    }
}
