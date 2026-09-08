<?php

namespace App\Service\Borderou\Pdf\Bank;

use App\Service\Borderou\Pdf\AbstractPdfStatementParser;
use App\Service\Borderou\Pdf\PdfPage;
use App\Service\Borderou\Pdf\PdfStatement;
use App\Service\Borderou\Pdf\PdfStatementNotRecognizedException;
use App\Service\Borderou\Pdf\PdfStatementTransaction;
use App\Service\Borderou\Pdf\PdfWord;

/**
 * Vista Bank (Romania): table "Descriere / Referinta / Debit / Credit / Sold" with the
 * date labels ("Data tranzactiei" / "Data valutei") printed on separate lines, amounts 1,234.56,
 * dates dd.MM.yyyy, "Sold initial" above the table and a per-page
 * "Rulaj debitor / Rulaj creditor / Sold final" totals line. Columns are split by word centre,
 * rows are separated by a vertical gap larger than 17pt.
 *
 * Glyphs: Vista's font maps "/" and the "ti" ligature to private-use code points (U+E000..U+F8FF).
 * The reference implementation resolves them by glyph width, which a text extractor does not expose;
 * here a PUA character between two digits becomes "/" and any other PUA character becomes "ti".
 */
class VistaPdfParser extends AbstractPdfStatementParser
{
    private const ISO = ['RON', 'EUR', 'USD', 'GBP', 'CHF', 'JPY', 'AUD', 'CAD', 'SEK', 'NOK', 'DKK'];
    private const MAX_INTRA_ROW_GAP = 17.0;
    private const LIGATURES = ['ﬀ' => 'ff', 'ﬁ' => 'fi', 'ﬂ' => 'fl', 'ﬃ' => 'ffi', 'ﬄ' => 'ffl'];

    public function getBankKey(): string
    {
        return 'vista';
    }

    public function getBankLabel(): string
    {
        return 'Vista Bank';
    }

    public function score(array $page1Words): int
    {
        $t = $this->normalise(implode(' ', $page1Words));
        foreach (['egnarobx', 'vistabank.ro', 'vista bank (romania)'] as $needle) {
            if (str_contains($t, $needle)) {
                return 100;
            }
        }

        return 0;
    }

    public function parse(array $pages): array
    {
        $pages = array_map(fn (PdfPage $p) => $this->resolveGlyphs($p), $pages);
        $page1Lines = $this->clusterLines($pages[0]);

        // --- metadata ----------------------------------------------------
        $iban = null;
        $currency = null;
        foreach ($page1Lines as $line) {
            $n = count($line);
            for ($i = 0; $i < $n - 1; $i++) {
                if ($iban === null && strcasecmp($line[$i]->text, 'IBAN') === 0) {
                    $cand = strtoupper(trim($line[$i + 1]->text));
                    if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $cand)) {
                        $iban = $cand;
                    }
                }
                if ($currency === null && strcasecmp($line[$i]->text, 'Moneda') === 0) {
                    $c = strtoupper(rtrim(trim($line[$i + 1]->text), ':;.,'));
                    $c = $c === 'LEI' ? 'RON' : $c;
                    if (in_array($c, self::ISO, true)) {
                        $currency = $c;
                    }
                }
            }
        }
        $currency ??= 'RON';
        $holder = $this->holderAboveAdresa($page1Lines);

        // --- table -------------------------------------------------------
        $transactions = [];
        $warnings = [];
        $running = null;
        $openingSearched = false;
        $opening = null;
        $printedDebit = $printedCredit = $printedFinal = null;
        $totalsFound = false;
        $headerSeen = false;
        $sumDebit = '0.00';
        $sumCredit = '0.00';

        foreach ($pages as $page) {
            $lines = $this->clusterLines($page);
            $headerIdx = null;
            foreach ($lines as $i => $line) {
                if ($this->isHeaderLine($line)) {
                    $headerIdx = $i;
                    break;
                }
            }
            if ($headerIdx === null) {
                continue;
            }
            $headerSeen = true;
            $bounds = $this->boundaries($lines[$headerIdx]);

            if (!$openingSearched) {
                $openingSearched = true;
                for ($i = 0; $i < $headerIdx; $i++) {
                    $l = $lines[$i];
                    if (count($l) === 3 && strcasecmp($l[0]->text, 'Sold') === 0 && stripos($l[1]->text, 'in') === 0) {
                        $v = $this->parseMoneyEn($l[2]->text);
                        if ($v !== null) {
                            $opening = $v;
                            $running = $v;
                            break;
                        }
                    }
                }
                if ($opening === null) {
                    $warnings[] = 'Nu am putut identifica soldul initial (Sold initial) in extrasul Vista Bank; soldul de pornire a fost dedus din primul rand.';
                }
            }

            $count = count($lines);
            $totalsIdx = null;
            for ($i = $headerIdx + 1; $i < $count; $i++) {
                $t = $this->normalise($this->lineText($lines[$i]));
                if (str_contains($t, 'rulaj debitor') && str_contains($t, 'sold final')) {
                    $totalsIdx = $i;
                    break;
                }
            }
            $bodyEnd = $totalsIdx ?? $count;
            $body = [];
            for ($i = $headerIdx + 1; $i < $bodyEnd; $i++) {
                $t = $this->normalise($this->lineText($lines[$i]));
                if ($t === 'data tranzactiei' || $t === 'data valutei') {
                    continue;
                }
                $body[] = $lines[$i];
            }
            if ($totalsIdx !== null && $totalsIdx + 1 < $count) {
                $cells = $this->splitByBoundaries($lines[$totalsIdx + 1], $bounds, true);
                $d = $this->parseMoneyEn($cells[3]);
                $c = $this->parseMoneyEn($cells[4]);
                $f = $this->parseMoneyEn($cells[5]);
                $totalsFound = $d !== null && $c !== null && $f !== null;
                if ($totalsFound) {
                    $printedDebit = $d;
                    $printedCredit = $c;
                    $printedFinal = $f;
                }
            }

            foreach ($this->groupRows($body, $bounds) as $rowLines) {
                $tx = $this->readRow($rowLines, $bounds, $running, $warnings);
                if ($tx === null) {
                    continue;
                }
                $transactions[] = $tx;
                if ($tx->isCredit()) {
                    $sumCredit = bcadd($sumCredit, $tx->credit, 2);
                } else {
                    $sumDebit = bcadd($sumDebit, $tx->debit, 2);
                }
            }
        }

        if (!$headerSeen) {
            throw new PdfStatementNotRecognizedException('Nu am putut identifica antetul tabelului (Descriere / Referinta / Debit / Credit / Sold) in PDF-ul Vista Bank. Este posibil ca formatul extrasului sa fi fost modificat.');
        }

        // --- validation ----------------------------------------------------
        if (!$totalsFound) {
            $warnings[] = 'Nu am putut identifica totalurile (Rulaj debitor / Rulaj creditor / Sold final) in extrasul Vista Bank.';
        } else {
            $computedFinal = $running ?? $opening;
            if ($mismatch = $this->closingMismatch($printedFinal, $computedFinal)) {
                $warnings[] = $mismatch;
            }
            if (bccomp($printedDebit, $sumDebit, 2) !== 0 || bccomp($printedCredit, $sumCredit, 2) !== 0) {
                $warnings[] = sprintf('Rulajele calculate (debitor %s, creditor %s) nu corespund cu cele tiparite in extras (debitor %s, creditor %s).', $sumDebit, $sumCredit, $printedDebit, $printedCredit);
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
            null,
            $opening,
            $printedFinal ?? $running,
            null,
            null,
            array_values(array_unique($warnings)),
        )];
    }

    // ------------------------------------------------------------------
    // Rows
    // ------------------------------------------------------------------

    /**
     * Groups body lines into rows: a gap larger than 17pt starts a new group; groups with
     * several anchor lines are split by nearest anchor.
     *
     * @param array<int, PdfWord[]> $body
     * @param float[] $bounds
     * @return array<int, array<int, PdfWord[]>>
     */
    private function groupRows(array $body, array $bounds): array
    {
        $groups = [];
        $current = [];
        $prevY = null;
        foreach ($body as $line) {
            $y = $this->centreY($line);
            if ($prevY !== null && ($y - $prevY) > self::MAX_INTRA_ROW_GAP) {
                $groups[] = $current;
                $current = [];
            }
            $current[] = $line;
            $prevY = $y;
        }
        if ($current !== []) {
            $groups[] = $current;
        }

        $rows = [];
        foreach ($groups as $group) {
            $anchors = [];
            foreach ($group as $i => $line) {
                if ($this->isAnchor($this->splitByBoundaries($line, $bounds, true))) {
                    $anchors[] = $i;
                }
            }
            if ($anchors === []) {
                // no amount/balance line: not a transaction (handled as a warning by the caller via readRow)
                $rows[] = $group;
                continue;
            }
            if (count($anchors) === 1) {
                $rows[] = $group;
                continue;
            }
            $buckets = array_fill_keys($anchors, []);
            $anchorY = [];
            foreach ($anchors as $a) {
                $anchorY[$a] = $this->centreY($group[$a]);
            }
            foreach ($group as $i => $line) {
                $y = $this->centreY($line);
                $best = null;
                $bestDist = INF;
                foreach ($anchorY as $a => $ay) {
                    $d = abs($ay - $y);
                    if ($d < $bestDist) {
                        $bestDist = $d;
                        $best = $a;
                    }
                }
                $buckets[$best][] = $line;
            }
            foreach ($anchors as $a) {
                $rows[] = $buckets[$a];
            }
        }

        return $rows;
    }

    /**
     * @param array<int, PdfWord[]> $rowLines
     * @param float[] $bounds
     * @param string[] $warnings
     */
    private function readRow(array $rowLines, array $bounds, ?string &$running, array &$warnings): ?PdfStatementTransaction
    {
        $split = array_map(fn (array $l) => $this->splitByBoundaries($l, $bounds, true), $rowLines);
        $raw = array_map(fn (array $l) => $this->lineText($l), $rowLines);
        $anchor = null;
        foreach ($split as $cells) {
            if ($this->isAnchor($cells)) {
                $anchor = $cells;
                break;
            }
        }
        $joined = implode(' | ', $raw);
        if ($anchor === null) {
            $warnings[] = 'Rand fara suma sau sold: ' . $joined;

            return null;
        }
        $dates = [];
        $desc = [];
        $refs = [];
        foreach ($split as $cells) {
            if ($this->looksLikeDate(trim($cells[0]))) {
                $dates[] = trim($cells[0]);
            }
            if (trim($cells[1]) !== '') {
                $desc[] = trim($cells[1]);
            }
            if (trim($cells[2]) !== '') {
                $refs[] = trim($cells[2]);
            }
        }
        if ($dates === []) {
            $warnings[] = 'Rand fara data: ' . $joined;

            return null;
        }
        $date = $this->parseDateStrict($dates[0]);
        if ($date === null) {
            $warnings[] = 'Rand cu data invalida: ' . $joined;

            return null;
        }
        $debit = $this->parseMoneyEn($anchor[3]);
        $credit = $this->parseMoneyEn($anchor[4]);
        if (($debit === null) === ($credit === null)) {
            $warnings[] = 'Rand cu suma ambigua (debit si credit): ' . $joined;

            return null;
        }
        $isCredit = $credit !== null;
        $amount = $this->bcAbs($isCredit ? $credit : $debit);
        $printed = $this->parseMoneyEn($anchor[5]);
        if ($running === null && $printed !== null) {
            // opening balance missing: derive it from the first row
            $running = $isCredit ? bcsub($printed, $amount, 2) : bcadd($printed, $amount, 2);
        }
        $before = $running ?? '0.00';
        $closing = $isCredit ? bcadd($before, $amount, 2) : bcsub($before, $amount, 2);
        if ($printed === null || bccomp($printed, $closing, 2) !== 0) {
            $warnings[] = sprintf('Soldul tiparit (%s) nu corespunde cu soldul calculat (%s) la tranzactia din %s.', $anchor[5] === '' ? '-' : $anchor[5], $closing, $dates[0]);
        }
        $running = $closing;

        return new PdfStatementTransaction(
            $date,
            implode(' ', $desc),
            $isCredit ? '0.00' : $amount,
            $isCredit ? $amount : '0.00',
            $refs === [] ? null : implode(' ', $refs),
            null,
            null,
            null,
            $closing,
            null,
            $raw,
        );
    }

    /**
     * @param string[] $cells
     */
    private function isAnchor(array $cells): bool
    {
        return $this->parseMoneyEn($cells[5]) !== null
            && ($this->parseMoneyEn($cells[3]) !== null || $this->parseMoneyEn($cells[4]) !== null);
    }

    /**
     * @param PdfWord[] $line
     */
    private function centreY(array $line): float
    {
        $sum = 0.0;
        foreach ($line as $w) {
            $sum += $w->centerY();
        }

        return $sum / max(1, count($line));
    }

    // ------------------------------------------------------------------
    // Layout
    // ------------------------------------------------------------------

    /**
     * Lines of a page, cut at the first footer line ("Vista Bank (Romania) ...").
     *
     * @return array<int, PdfWord[]>
     */
    private function clusterLines(PdfPage $page): array
    {
        $out = [];
        foreach ($this->clusterer->cluster($page->words, 2.5) as $line) {
            if (str_starts_with($this->normalise($this->lineText($line)), 'vista bank (romania)')) {
                break;
            }
            $out[] = $line;
        }

        return $out;
    }

    /**
     * @param PdfWord[] $line
     */
    private function isHeaderLine(array $line): bool
    {
        $desc = $ref = $debit = $credit = $sold = false;
        foreach ($line as $w) {
            $t = $this->normalise($w->text);
            if (str_starts_with($t, 'descriere')) {
                $desc = true;
            } elseif (str_starts_with($t, 'referinta')) {
                $ref = true;
            } elseif ($t === 'debit') {
                $debit = true;
            } elseif ($t === 'credit') {
                $credit = true;
            } elseif ($t === 'sold') {
                $sold = true;
            }
        }

        return $desc && $ref && $debit && $credit && $sold;
    }

    /**
     * Column boundaries from the label centres; words are assigned by their own centre.
     *
     * @param PdfWord[] $header
     * @return float[]
     */
    private function boundaries(array $header): array
    {
        $c = [];
        foreach ($header as $w) {
            $t = $this->normalise($w->text);
            $key = null;
            if (str_starts_with($t, 'descriere')) {
                $key = 'desc';
            } elseif (str_starts_with($t, 'referinta')) {
                $key = 'ref';
            } elseif ($t === 'debit') {
                $key = 'debit';
            } elseif ($t === 'credit') {
                $key = 'credit';
            } elseif ($t === 'sold') {
                $key = 'sold';
            }
            if ($key !== null && !isset($c[$key])) {
                $c[$key] = $w->centerX();
            }
        }
        $bDescRef = $c['ref'] - ($c['debit'] - $c['ref']) / 2;

        return [
            -INF,
            2 * $c['desc'] - $bDescRef,
            $bDescRef,
            ($c['ref'] + $c['debit']) / 2,
            ($c['debit'] + $c['credit']) / 2,
            ($c['credit'] + $c['sold']) / 2,
        ];
    }

    /**
     * Customer block printed above "Adresa" in the same column.
     *
     * @param array<int, PdfWord[]> $lines
     */
    private function holderAboveAdresa(array $lines): ?string
    {
        foreach ($lines as $k => $line) {
            foreach ($line as $w) {
                if (stripos($w->text, 'Adresa') !== 0) {
                    continue;
                }
                if ($k === 0) {
                    return null;
                }
                $columnLeft = $w->x0 - 5.0;
                $parts = [];
                for ($i = 0; $i < $k; $i++) {
                    foreach ($lines[$i] as $lw) {
                        if ($lw->x0 >= $columnLeft && trim($lw->text) !== '') {
                            $parts[] = trim($lw->text);
                        }
                    }
                }
                $holder = trim(implode(' ', $parts));

                return $holder === '' ? null : $holder;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Glyphs
    // ------------------------------------------------------------------

    private function resolveGlyphs(PdfPage $page): PdfPage
    {
        $changed = false;
        $words = [];
        foreach ($page->words as $w) {
            $t = $this->text($w->text);
            if ($t === $w->text) {
                $words[] = $w;
                continue;
            }
            $changed = true;
            if ($t === '') {
                continue;
            }
            $words[] = new PdfWord($t, $w->x0, $w->y0, $w->x1, $w->y1);
        }

        return $changed ? new PdfPage($page->number, $words, $page->width, $page->height) : $page;
    }

    /**
     * Ligature expansion plus the private-use glyph fallback described in the class docblock.
     */
    private function text(string $text): string
    {
        $text = strtr($text, self::LIGATURES);
        if (!preg_match('/[\x{E000}-\x{F8FF}]/u', $text)) {
            return $text;
        }
        $text = preg_replace('/(?<=\d)[\x{E000}-\x{F8FF}](?=\d)/u', '/', $text) ?? $text;

        return preg_replace('/[\x{E000}-\x{F8FF}]/u', 'ti', $text) ?? $text;
    }
}
