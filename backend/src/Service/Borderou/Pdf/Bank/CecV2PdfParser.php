<?php

namespace App\Service\Borderou\Pdf\Bank;

use App\Service\Borderou\Pdf\AbstractPdfStatementParser;
use App\Service\Borderou\Pdf\PdfPage;
use App\Service\Borderou\Pdf\PdfStatement;
use App\Service\Borderou\Pdf\PdfStatementNotRecognizedException;
use App\Service\Borderou\Pdf\PdfStatementTransaction;
use App\Service\Borderou\Pdf\PdfWord;

/**
 * CEC Bank Mobile Banking "CONTUL TAU CURENT / ACTIVITATE" layout: table
 * "Data tranzactiei / Data decontarii / Ref tranz/Nr doc / Rulaj debitor / Rulaj creditor / Sold dupa tranzactie",
 * amounts 1,234.56. A transaction starts on a date line; its amount sits on the following lines.
 */
class CecV2PdfParser extends AbstractPdfStatementParser
{
    protected const ISO = ['RON', 'EUR', 'USD', 'GBP', 'CHF', 'JPY', 'AUD', 'CAD', 'SEK', 'NOK', 'DKK'];
    protected const IBAN_RX = '/\b[A-Z]{2}\d{2}[A-Z0-9]{10,30}\b/';
    protected const IBAN_LABEL_RX = '/\b(IBAN|cont\s+curent|account|current\s+account|principal)\b/iu';

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
        $f = $this->flags($t);
        if ($f['a'] && !$f['b'] && $f['c'] && !$f['d'] && $f['e'] && $f['f'] && $f['g'] && $f['h'] && !$f['i']) {
            return 100;
        }

        return $this->fallbackScore($f);
    }

    /**
     * @return array<string, bool>
     */
    protected function flags(string $t): array
    {
        return [
            'a' => str_contains($t, 'cec bank'),
            'b' => str_contains($t, 'www.ceconline.ro'),
            'c' => str_contains($t, 'www.cec.ro'),
            'd' => str_contains($t, 'titular cont'),
            'e' => str_contains($t, 'mobile banking'),
            'f' => str_contains($t, 'extras de cont'),
            'g' => str_contains($t, 'contul tau curent'),
            'h' => str_contains($t, 'activitate'),
            'i' => str_contains($t, 'descoperit de cont'),
        ];
    }

    /**
     * @param array<string, bool> $f
     */
    protected function fallbackScore(array $f): int
    {
        if (!$f['b'] && $f['c']) {
            return 70;
        }

        return ($f['a'] || $f['c']) ? 50 : 0;
    }

    public function parse(array $pages): array
    {
        $page1 = $pages[0];
        $warnings = [];
        $headerWords = $this->headerBlock($page1);
        $iban = $this->bestIban(implode(' ', array_map(static fn (PdfWord $w) => $w->text, $headerWords)));
        if ($iban === null) {
            $warnings[] = 'Nu a fost gasit IBAN-ul contului in extras.';
        }
        $holder = $this->holderFromBlock($headerWords);
        $currency = $this->firstIsoCurrency($headerWords, self::ISO) ?? 'RON';

        $lines = $this->linesInBand($pages, 70.0, 750.0);
        [$opening, $printedClosing] = $this->disponibilBalances($lines);

        // --- table header ------------------------------------------------
        $headerIdx = null;
        $headerLineCount = 0;
        foreach ($lines as $i => $w) {
            $count = $this->headerAt($lines, $i);
            if ($count > 0) {
                $headerIdx = $i;
                $headerLineCount = $count;
                break;
            }
        }
        if ($headerIdx === null) {
            throw new PdfStatementNotRecognizedException('Nu am putut identifica antetul tabelului (Data / Detalii / Rulaj debitor / Rulaj creditor) in PDF-ul CEC Bank. Este posibil ca formatul extrasului sa fi fost modificat sau ca banca sa nu fie inca suportata.');
        }
        $hw = [];
        for ($k = 0; $k < $headerLineCount; $k++) {
            foreach ($lines[$headerIdx + $k] as $w) {
                $hw[] = $w;
            }
        }
        if (count($hw) < 6) {
            throw new PdfStatementNotRecognizedException('Nu am putut identifica antetul tabelului (Data / Detalii / Rulaj debitor / Rulaj creditor) in PDF-ul CEC Bank. Este posibil ca formatul extrasului sa fi fost modificat sau ca banca sa nu fie inca suportata.');
        }
        $dateWord = $this->findWord($hw, fn (string $t) => $this->normalise($t) === 'tranzactiei', true);
        $refWord = $this->findWord($hw, function (string $t): bool {
            $n = $this->normalise($t);

            return $n === 'ref' || $n === 'doc' || str_starts_with($n, 'nr') || str_contains($n, '/nr');
        });
        $debitWord = $this->findWord($hw, fn (string $t) => $this->normalise($t) === 'debitor');
        $creditWord = $this->findWord($hw, fn (string $t) => $this->normalise($t) === 'creditor');
        $soldWord = $this->findWord($hw, fn (string $t) => $this->normalise($t) === 'tranzactie', true);
        $bounds = [-INF, $dateWord->x1 + 8.0, $refWord->x0 - 8.0, $debitWord->x0 - 24.0, $creditWord->x0 - 20.0, $soldWord->x0 - 12.8];

        // --- rows --------------------------------------------------------
        $transactions = [];
        $running = $opening;
        $current = null;
        $pendingDesc = [];
        $pendingRef = '';
        $flush = function () use (&$current, &$transactions, &$warnings): void {
            if ($current === null) {
                return;
            }
            if ($current['amount'] === null) {
                $warnings[] = 'Tranzactie fara suma: ' . $current['date']->format('d.m.Y') . ' ' . $current['description'];
            }
            $transactions[] = $this->buildTransaction($current);
            $current = null;
        };

        $n = count($lines);
        for ($i = $headerIdx + $headerLineCount; $i < $n; $i++) {
            $line = $lines[$i];
            $repeat = $this->headerAt($lines, $i);
            if ($repeat > 0) {
                $i += $repeat - 1;
                continue;
            }
            $t = array_map(fn (PdfWord $w) => $this->normalise($w->text), $line);
            if (count($line) <= 3 && $this->anyContains($t, 'detalii') && $this->anyContains($t, 'tranzactii')) {
                continue;
            }
            $c = $this->splitByBoundaries($line, $bounds);
            $joined = trim(implode(' ', $c));
            $d = trim($c[1] ?? '');
            $ref = trim($c[2] ?? '');
            $deb = $this->parseMoneyEn($c[3] ?? '');
            $cre = $this->parseMoneyEn($c[4] ?? '');
            $sold = $this->parseMoneyEn($c[5] ?? '');
            $date = $this->parseDateStrict($d);

            if ($date !== null) {
                $flush();
                $current = [
                    'date' => $date,
                    'description' => trim(implode(' ', $pendingDesc)),
                    'reference' => $pendingRef,
                    'opening' => $running ?? '0.00',
                    'closing' => $running ?? '0.00',
                    'amount' => null,
                    'credit' => false,
                    'raw' => [$joined],
                ];
                $pendingDesc = [];
                $pendingRef = '';
                continue;
            }
            if ($current === null) {
                if ($d !== '') {
                    $pendingDesc[] = $d;
                }
                if ($pendingRef === '' && $ref !== '') {
                    $pendingRef = $ref;
                }
                continue;
            }
            if ($d !== '') {
                $current['description'] = trim($current['description'] . ' ' . $d);
            }
            if ($current['reference'] === '' && $ref !== '') {
                $current['reference'] = $ref;
            }
            $current['raw'][] = $joined;
            if ($current['amount'] === null) {
                if ($deb !== null && bccomp($deb, '0', 2) !== 0) {
                    $current['credit'] = false;
                    $current['amount'] = $this->bcAbs($deb);
                    $current['closing'] = bcsub($current['opening'], $current['amount'], 2);
                } elseif ($cre !== null && bccomp($cre, '0', 2) !== 0) {
                    $current['credit'] = true;
                    $current['amount'] = $this->bcAbs($cre);
                    $current['closing'] = bcadd($current['opening'], $current['amount'], 2);
                } elseif ($sold !== null && $running !== null) {
                    $diff = bcsub($sold, $current['opening'], 2);
                    $current['credit'] = bccomp($diff, '0', 2) >= 0;
                    $current['amount'] = $this->bcAbs($diff);
                    $current['closing'] = $sold;
                } else {
                    continue;
                }
                $running = $current['closing'];
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

    /**
     * Header at line $i: 1 for the single-line shape, 3 for the three-line shape, 0 otherwise.
     *
     * @param array<int, PdfWord[]> $lines
     */
    protected function headerAt(array $lines, int $i): int
    {
        $t0 = array_map(fn (PdfWord $w) => $this->normalise($w->text), $lines[$i]);
        if (count($t0) >= 6 && in_array('tranzactiei', $t0, true) && $this->anyStarts($t0, 'doc')
            && in_array('debitor', $t0, true) && in_array('creditor', $t0, true)) {
            return 1;
        }
        if (!isset($lines[$i + 2])) {
            return 0;
        }
        $t1 = array_map(fn (PdfWord $w) => $this->normalise($w->text), $lines[$i + 1]);
        $t2 = array_map(fn (PdfWord $w) => $this->normalise($w->text), $lines[$i + 2]);
        $ok0 = $this->anyContains($t0, 'data') && (in_array('ref', $t0, true) || $this->anyContains($t0, 'nr')) && $this->anyContains($t0, 'sold');
        $ok1 = $this->anyContains($t1, 'decontar') && $this->anyContains($t1, 'detalii') && in_array('debitor', $t1, true) && in_array('creditor', $t1, true);
        $ok2 = in_array('tranzactiei', $t2, true) && in_array('doc', $t2, true) && in_array('tranzactie', $t2, true);

        return ($ok0 && $ok1 && $ok2) ? 3 : 0;
    }

    /**
     * @param array{date: \DateTimeImmutable, description: string, reference: string, closing: string, amount: ?string, credit: bool, raw: string[]} $tx
     */
    protected function buildTransaction(array $tx): PdfStatementTransaction
    {
        $desc = trim(preg_replace('/\s{2,}/', ' ', $tx['description']) ?? $tx['description']);
        $amount = $tx['amount'] ?? '0.00';
        $name = null;
        if (preg_match('/\b(?:Beneficiar|Ordonator|Platitor)\s*:\s*(.+?)(?=\s+\b[A-Za-z][A-Za-z ]{1,25}:|$)/iu', $desc, $m)) {
            $name = trim($m[1]) ?: null;
        }

        return new PdfStatementTransaction(
            $tx['date'],
            $desc,
            $tx['credit'] ? '0.00' : $amount,
            $tx['credit'] ? $amount : '0.00',
            $tx['reference'] !== '' ? $tx['reference'] : null,
            null,
            $name,
            null,
            $tx['closing'],
            null,
            $tx['raw'],
        );
    }

    // ------------------------------------------------------------------
    // Header block (shared with the V3 layout)
    // ------------------------------------------------------------------

    /**
     * Upper-left block of page 1 (Top > 90, Top < 700, Right < 300), cut at the "CONTUL TAU CURENT" banner.
     *
     * @return PdfWord[]
     */
    protected function headerBlock(PdfPage $page1): array
    {
        $words = array_values(array_filter($this->wordsInBand($page1, 90.0, 700.0), static fn (PdfWord $w) => $w->x1 < 300.0));
        $out = [];
        foreach ($this->clusterer->cluster($words, 2.5) as $line) {
            $t = $this->normalise($this->lineText($line));
            if (str_contains($t, 'contul') && str_contains($t, 'curent')) {
                break;
            }
            foreach ($line as $w) {
                $out[] = $w;
            }
        }

        return $out;
    }

    /**
     * @param PdfWord[] $block
     */
    protected function holderFromBlock(array $block): ?string
    {
        $lines = $this->clusterer->cluster($block, 1.5);
        if ($lines === []) {
            return null;
        }
        $s = trim($this->lineText($lines[0]));

        return $s === '' ? null : $s;
    }

    /**
     * Mod-97 valid IBAN candidates, scored (RO prefix, length 24, CECE bank code, label nearby).
     */
    protected function bestIban(string $input): ?string
    {
        if (!preg_match_all(self::IBAN_RX, $input, $m)) {
            return null;
        }
        $hasLabel = (bool) preg_match(self::IBAN_LABEL_RX, $input);
        $best = null;
        $bestScore = -1.0;
        foreach ($m[0] as $raw) {
            $iban = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '');
            if (!$this->ibanChecksumValid($iban)) {
                continue;
            }
            $score = 0.0;
            if (str_starts_with($iban, 'RO')) {
                $score += 0.6;
            }
            if (strlen($iban) === 24) {
                $score += 0.2;
            }
            if (substr($iban, 4, 4) === 'CECE') {
                $score += 0.2;
            }
            if ($hasLabel) {
                $score += 0.1;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $iban;
            }
        }

        return $best;
    }

    protected function ibanChecksumValid(string $iban): bool
    {
        $len = strlen($iban);
        if ($len < 15 || $len > 34) {
            return false;
        }
        $s = substr($iban, 4) . substr($iban, 0, 4);
        $r = 0;
        foreach (str_split($s) as $ch) {
            $digits = ctype_alpha($ch) ? (string) (ord($ch) - 55) : $ch;
            foreach (str_split($digits) as $d) {
                $r = ($r * 10 + (int) $d) % 97;
            }
        }

        return $r === 1;
    }

    /**
     * "Disponibil la data de ... Disponibil ..." summary: values three lines below, word 0 = opening,
     * word 6 = closing.
     *
     * @param array<int, PdfWord[]> $lines
     * @param null|callable(string): ?string $money amount parser (default: English style)
     * @return array{0: ?string, 1: ?string}
     */
    protected function disponibilBalances(array $lines, ?callable $money = null): array
    {
        $money ??= fn (string $v) => $this->parseMoneyEn($v);
        foreach ($lines as $k => $w) {
            if ($k === 0 || count($w) < 8) {
                continue;
            }
            $first = array_map(fn (PdfWord $x) => $this->normalise($x->text), array_slice($w, 0, 5));
            if ($first !== ['disponibil', 'la', 'data', 'de', 'disponibil']) {
                continue;
            }
            $values = $lines[$k + 3] ?? [];
            $opening = isset($values[0]) ? $money($values[0]->text) : null;
            $closing = isset($values[6]) ? $money($values[6]->text) : null;

            return [$opening, $closing];
        }

        return [null, null];
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
     * @param string[] $tokens
     */
    protected function anyContains(array $tokens, string $needle): bool
    {
        foreach ($tokens as $t) {
            if (str_contains($t, $needle)) {
                return true;
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
            throw new PdfStatementNotRecognizedException('Antetul tabelului CEC Bank este incomplet.');
        }

        return $found;
    }
}
