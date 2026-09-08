<?php

namespace App\Service\Borderou\Pdf\Bank;

use App\Service\Borderou\Pdf\AbstractPdfStatementParser;
use App\Service\Borderou\Pdf\PdfStatement;
use App\Service\Borderou\Pdf\PdfStatementNotRecognizedException;
use App\Service\Borderou\Pdf\PdfStatementTransaction;
use App\Service\Borderou\Pdf\PdfWord;

/**
 * BRD bilingual layout (2023+): "Data inregistrarii / Detalii / Beneficiar / Referinta / Suma",
 * one signed amount column, dates dd.MM.yyyy, balances printed as labels on page 1.
 */
class BrdV2PdfParser extends AbstractPdfStatementParser
{
    private const ISO = ['RON', 'EUR', 'USD', 'GBP', 'CHF', 'JPY', 'AUD', 'CAD', 'SEK', 'NOK', 'DKK'];

    public function getBankKey(): string
    {
        return 'brd';
    }

    public function getBankLabel(): string
    {
        return 'BRD - Groupe Societe Generale';
    }

    public function score(array $page1Words): int
    {
        $t = $this->normalise(implode(' ', $page1Words));
        $a = str_contains($t, 'acest extras de cont este valabil fara semnatura') || str_contains($t, 'this statement is valid without signature');
        $b = str_contains($t, 'detinator cont') || str_contains($t, 'account holder');
        $c = str_contains($t, 'brd');
        $d = str_contains($t, 'de la data de') || str_contains($t, 'date from');
        $e = str_contains($t, 'numar cont') || str_contains($t, 'account number');
        if ($a && $b && $c && $d && $e) {
            return 100;
        }
        if ($b || $c) {
            return 50;
        }

        return $a ? 50 : 0;
    }

    public function parse(array $pages): array
    {
        $page1 = $pages[0];

        // Header words = lines above the "Sold initial" anchor.
        $headerLines = $this->clusterer->cluster($this->wordsInBand($page1, 90.0), 1.5);
        $headerWords = [];
        foreach ($headerLines as $line) {
            $t = $this->normalise($this->lineText($line));
            if ((str_contains($t, 'sold') && str_contains($t, 'initial')) || str_contains($t, 'opening balance') || str_contains($t, 'initial balance')) {
                break;
            }
            foreach ($line as $w) {
                $headerWords[] = $w;
            }
        }
        if ($headerWords === []) {
            $headerWords = $this->wordsInBand($page1, 90.0);
        }
        usort($headerWords, static fn (PdfWord $a, PdfWord $b) => [$a->y0, $a->x0] <=> [$b->y0, $b->x0]);

        $iban = $this->firstWordMatching($headerWords, '/\b[A-Z]{2}\d{2}[A-Z0-9]{10,30}\b/')?->text;
        $holder = $this->holderUnderDetinatorCont($headerWords);
        $currency = $this->currencyAfterWord($headerWords, 'cont', 15, self::ISO) ?? $this->firstIsoCurrency($headerWords, self::ISO) ?? 'RON';

        $bandWords = $this->wordsInBand($page1, 70.0, 630.0);
        $opening = $this->soldAmount($bandWords, 'initial');
        $printedClosing = $this->soldAmount($bandWords, 'final');

        // --- table -------------------------------------------------------
        $lines = $this->linesInBand($pages, 70.0, 630.0);
        $headerIdx = null;
        foreach ($lines as $i => $w) {
            if (count($w) < 10) {
                continue;
            }
            $t = array_map(fn (PdfWord $x) => $this->normalise($x->text), $w);
            $ok = ($this->seq($t, ['data', 'inregistrarii']) || $this->seq($t, ['register', 'date']) || $this->seq($t, ['booking', 'date']))
                && $this->anyStarts($t, 'detalii', 'details')
                && $this->anyStarts($t, 'beneficiar', 'beneficiary')
                && (in_array('referinta', $t, true) || in_array('reference', $t, true))
                && (in_array('suma', $t, true) || in_array('amount', $t, true));
            if ($ok) {
                $headerIdx = $i;
                break;
            }
        }
        if ($headerIdx === null || !in_array(count($lines[$headerIdx]), [10, 11], true)) {
            throw new PdfStatementNotRecognizedException('Nu am putut identifica antetul tabelului (Data inregistrarii / Detalii / Beneficiar / Referinta / Suma) in PDF-ul BRD. Este posibil ca formatul extrasului sa fi fost modificat.');
        }
        $hw = $lines[$headerIdx];
        $data = $this->findOne($hw, ['data', 'booking']);
        $detalii = $this->findOne($hw, ['detalii', 'details']);
        $benef = $this->findOne($hw, ['beneficiar', 'beneficiary']);
        $ref = $this->findOne($hw, ['referinta', 'customer'], true);
        $suma = $this->findOne($hw, ['suma', 'amount'], true);
        $bounds = [-INF, $data->x0 - 8.0, $detalii->x0 - 8.0, $benef->x0 - 24.0, $ref->x0 - 12.8, $suma->x0 - 8.0];

        $isHeader = function (array $c): bool {
            $l = array_map(fn ($x) => $this->normalise($x), $c);
            if (count($l) >= 5 && strcasecmp(trim($c[3] ?? ''), 'IBAN') === 0) {
                return true;
            }
            $first = $l[1] ?? '';
            $startsDate = str_starts_with($first, 'data inreg') || str_starts_with($first, 'data valutei') || str_starts_with($first, 'booking date') || str_starts_with($first, 'value date');
            $second = $l[2] ?? '';
            $detOk = str_starts_with($second, 'detalii') || str_starts_with($second, 'details') || $second === '';
            $benOk = false;
            $refOk = false;
            foreach ($l as $cell) {
                if (str_starts_with($cell, 'beneficiar') || str_starts_with($cell, 'beneficiary') || str_starts_with($cell, 'cui / cnp')) {
                    $benOk = true;
                }
                if (str_starts_with($cell, 'referinta') || str_starts_with($cell, 'customer') || str_starts_with($cell, 'bank reference')) {
                    $refOk = true;
                }
            }

            return count($l) >= 5 && $startsDate && $detOk && $benOk && $refOk;
        };

        $transactions = [];
        $warnings = [];
        $running = $opening;
        $current = null;
        $beneficiary = '';
        $date = null;
        $flush = function () use (&$current, &$beneficiary, &$transactions): void {
            if ($current === null) {
                return;
            }
            $desc = trim(preg_replace('/\s{2,}/', ' ', trim($current['description'] . ' ' . trim($beneficiary))) ?? '');
            $benefName = null;
            $benefIban = null;
            if (trim($beneficiary) !== '') {
                $benefIban = $this->extractIban($beneficiary);
                $benefName = trim(preg_replace('/\s{2,}/', ' ', preg_replace('/\b[A-Z]{2}\d{2}[A-Z0-9]{10,30}\b|\b\d{5,10}\b/', '', $beneficiary) ?? '') ?? '') ?: null;
            }
            $transactions[] = new PdfStatementTransaction(
                $current['date'], $desc, $current['debit'], $current['credit'], $current['reference'] ?: null,
                null, $benefName, $benefIban, $current['closing'], null, $current['raw'],
            );
            $current = null;
            $beneficiary = '';
        };

        for ($i = $headerIdx + 3; $i < count($lines); $i++) {
            $c = $this->splitByBoundaries($lines[$i], $bounds);
            if ($isHeader($c)) {
                continue;
            }
            $v = $this->parseSignedMoneyRo($c[5] ?? '');
            $parsedDate = $this->parseDateStrict($c[1] ?? '');
            if ($v !== null) {
                $flush();
                if ($parsedDate) {
                    $date = $parsedDate;
                }
                if ($date === null) {
                    $warnings[] = 'Rand cu suma fara data: ' . implode(' ', $c);
                    continue;
                }
                $isCredit = bccomp($v, '0', 2) > 0;
                $amount = $this->bcAbs($v);
                $open = $running ?? '0.00';
                $closing = $isCredit ? bcadd($open, $amount, 2) : bcsub($open, $amount, 2);
                $running = $closing;
                $beneficiary .= trim($c[3] ?? '');
                $current = [
                    'date' => $date,
                    'description' => trim($c[2] ?? ''),
                    'reference' => trim($c[4] ?? ''),
                    'debit' => $isCredit ? '0.00' : $amount,
                    'credit' => $isCredit ? $amount : '0.00',
                    'closing' => $closing,
                    'raw' => [implode(' ', $c)],
                ];
                continue;
            }
            if ($current !== null) {
                if (trim($c[2] ?? '') !== '') {
                    $current['description'] .= ' ' . trim($c[2]);
                }
                if (trim($c[3] ?? '') !== '') {
                    $beneficiary .= ' ' . trim($c[3]);
                }
                $current['raw'][] = implode(' ', $c);
            }
        }
        $flush();

        if ($mismatch = $this->closingMismatch($printedClosing, $running)) {
            $warnings[] = $mismatch;
        }
        if ($transactions === []) {
            $warnings[] = 'Nu a fost gasita nicio tranzactie in extras.';
        }

        return [new PdfStatement($this->getBankKey(), $this->getBankLabel(), $iban, $currency, $transactions, $holder, null, $opening, $printedClosing, null, null, $warnings)];
    }

    private function parseSignedMoneyRo(string $v): ?string
    {
        $v = str_replace(["\u{2212}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{00AD}"], '-', trim($v));

        return $this->parseMoneyRo($v);
    }

    /**
     * "Sold initial: 1.234,56" / "Opening balance 1.234,56" from raw page-1 tokens.
     *
     * @param PdfWord[] $words
     */
    private function soldAmount(array $words, string $label): ?string
    {
        $tokens = array_map(fn (PdfWord $w) => trim($this->normalise($w->text), ":-\u{2013}\u{2014}"), $words);
        $n = count($tokens);
        $firstMoneyAfter = function (int $from) use ($tokens, $n): ?string {
            for ($k = $from; $k < $n; $k++) {
                $m = $this->parseSignedMoneyRo($tokens[$k]);
                if ($m !== null) {
                    return $m;
                }
            }

            return null;
        };
        for ($i = 0; $i < $n; $i++) {
            if ($tokens[$i] === 'sold') {
                for ($j = $i + 1; $j <= min($n - 1, $i + 3); $j++) {
                    if (str_starts_with($tokens[$j], $label)) {
                        return $firstMoneyAfter($j + 1);
                    }
                }
            }
        }
        $qualifier = $label === 'initial' ? 'opening' : 'closing';
        for ($i = 0; $i < $n; $i++) {
            if ($tokens[$i] === $qualifier) {
                for ($j = $i + 1; $j <= min($n - 1, $i + 2); $j++) {
                    if (str_starts_with($tokens[$j], 'balance')) {
                        return $firstMoneyAfter($j + 1);
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param PdfWord[] $headerWords
     */
    private function holderUnderDetinatorCont(array $headerWords): ?string
    {
        $lines = $this->clusterer->cluster($headerWords, 1.5);
        foreach ($lines as $i => $line) {
            $t = $this->normalise($this->lineText($line));
            if (!((str_contains($t, 'detinator') && str_contains($t, 'cont')) || (str_contains($t, 'account') && str_contains($t, 'holder')))) {
                continue;
            }
            $maxX = 600.0;
            foreach ($line as $w) {
                if (str_starts_with(strtolower($w->text), 'numar')) {
                    $maxX = $w->x0;
                    break;
                }
            }
            if ($maxX === 600.0) {
                foreach ($line as $w) {
                    if (strcasecmp($w->text, 'Number') === 0) {
                        $maxX = $w->x0;
                        break;
                    }
                }
            }
            for ($j = $i + 1; $j < count($lines); $j++) {
                $parts = [];
                foreach ($lines[$j] as $w) {
                    if ($w->x1 < $maxX) {
                        $s = trim($w->text, ':');
                        if ($s !== '') {
                            $parts[] = $s;
                        }
                    }
                }
                if ($parts !== []) {
                    return implode(' ', $parts);
                }
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
     * @param string[] $alternatives normalised texts
     */
    private function findOne(array $words, array $alternatives, bool $last = false): PdfWord
    {
        $found = null;
        foreach ($words as $w) {
            if (in_array($this->normalise($w->text), $alternatives, true)) {
                $found = $w;
                if (!$last) {
                    break;
                }
            }
        }
        if (!$found) {
            throw new PdfStatementNotRecognizedException('Antetul tabelului BRD este incomplet.');
        }

        return $found;
    }
}
