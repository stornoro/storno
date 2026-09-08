<?php

namespace App\Service\Borderou\Pdf\Bank;

use App\Service\Borderou\Pdf\AbstractPdfStatementParser;
use App\Service\Borderou\Pdf\PdfStatement;
use App\Service\Borderou\Pdf\PdfStatementNotRecognizedException;
use App\Service\Borderou\Pdf\PdfStatementTransaction;
use App\Service\Borderou\Pdf\PdfWord;

/**
 * Intesa Sanpaolo Bank Romania, layout v1 (mixed-case, two-row header
 * "Data Operației / Data Valutei / Operație / Curs BNR / Ref.Client / Tip Operație / Rulaj / Sold"),
 * amounts 1,234.56, dates dd.MM.yyyy, "Sold initial" / "Sold final" rows in the Tip operatie column,
 * turnover summary "RULAJ DEBITOR / RULAJ CREDITOR / RULAJ NET" after the table.
 */
class IntesaPdfParser extends AbstractPdfStatementParser
{
    private const ISO = ['RON', 'EUR', 'USD', 'GBP', 'CHF', 'JPY', 'AUD', 'CAD', 'SEK', 'NOK', 'DKK'];
    private const HEADER_VOCAB = ['Data', 'Operației', 'Operatiei', 'Valutei', 'Operație', 'Operatie', 'Curs', 'BNR', 'Ref.Client', 'Tip', 'Rulaj', 'Sold'];
    private const MONEY_RX = '/^-?[\d,]+\.\d{2}$/';

    public function getBankKey(): string
    {
        return 'intesa';
    }

    public function getBankLabel(): string
    {
        return 'Intesa Sanpaolo Bank';
    }

    public function score(array $page1Words): int
    {
        $exact = array_map('trim', $page1Words);
        if (in_array('RULAJ', $exact, true) && in_array('SOLD', $exact, true) && in_array('OPERATIE', $exact, true)) {
            return 0; // v2 layout
        }
        $t = $this->normalise(implode(' ', $page1Words));
        if (str_contains($t, 'www.intesasanpaolobank.ro')) {
            return 100;
        }
        $a = str_contains($t, 'intesa sanpaolo bank');
        $b = str_contains($t, 'wbanro22xxx');
        if ($a && $b) {
            return 100;
        }

        return ($a || $b) ? 50 : 0;
    }

    public function parse(array $pages): array
    {
        $page1 = $pages[0];
        $words = $this->wordsInBand($page1, 90.0);
        usort($words, static fn (PdfWord $a, PdfWord $b) => [$a->y0, $a->x0] <=> [$b->y0, $b->x0]);

        // --- metadata ----------------------------------------------------
        $holder = $this->holderAboveAdresa($words);
        $iban = $this->ibanAfterCodIban($words);
        $currency = $this->currencyAfterMoneda($words) ?? $this->firstIsoCurrency($words, self::ISO) ?? 'RON';

        // --- lines (all pages, Top > 90), tagged with page -----------------
        $lines = [];
        foreach ($pages as $page) {
            foreach ($this->clusterer->cluster($this->wordsInBand($page, 90.0), 2.5) as $line) {
                $lines[] = ['page' => $page->number, 'words' => $line];
            }
        }

        $anchorIdx = null;
        foreach ($lines as $i => $entry) {
            foreach ($entry['words'] as $w) {
                if ($w->text === 'Rulaj') {
                    $anchorIdx = $i;
                    break 2;
                }
            }
        }
        if ($anchorIdx === null) {
            throw new PdfStatementNotRecognizedException('Nu am putut identifica antetul tabelului (Data Operatiei / Data Valutei / Operatie / Tip Operatie / Rulaj / Sold) in PDF-ul Intesa Sanpaolo. Este posibil ca formatul extrasului sa fi fost modificat.');
        }
        $band = $lines[$anchorIdx]['words'];
        $page = $lines[$anchorIdx]['page'];
        for ($i = $anchorIdx - 1; $i >= 0 && $lines[$i]['page'] === $page && $this->isHeaderFragment($lines[$i]['words']); $i--) {
            $band = array_merge($band, $lines[$i]['words']);
        }
        for ($i = $anchorIdx + 1; $i < count($lines) && $lines[$i]['page'] === $page && $this->isHeaderFragment($lines[$i]['words']); $i++) {
            $band = array_merge($band, $lines[$i]['words']);
        }
        $bounds = $this->boundaries($band);

        // --- 4.1 collect table lines --------------------------------------
        $tableLines = [];
        $stopIndex = null;
        $inTable = false;
        $currentPage = null;
        foreach ($lines as $i => $entry) {
            if ($entry['page'] !== $currentPage) {
                $currentPage = $entry['page'];
                $inTable = false;
            }
            if ($this->isHeaderFragment($entry['words'])) {
                $inTable = true;
                continue;
            }
            if (!$inTable) {
                continue;
            }
            $cells = $this->splitByBoundaries($entry['words'], $bounds);
            $y = 0.0;
            foreach ($entry['words'] as $w) {
                $y += $w->y1;
            }
            $y /= max(1, count($entry['words']));
            $tableLines[] = ['cells' => $cells, 'y' => $y, 'raw' => trim(implode(' ', $cells))];
            if (str_contains($this->normalise($cells[5]), 'final')) {
                $stopIndex = $i + 1;
                break;
            }
        }

        // --- 4.2 printed turnover summary ----------------------------------
        $printedDebit = null;
        $printedCredit = null;
        $summaryFound = false;
        if ($stopIndex !== null) {
            [$printedDebit, $printedCredit, $summaryFound] = $this->readTurnoverSummary($lines, $stopIndex);
        }

        // --- 4.3 group into rows --------------------------------------------
        $rows = $this->groupRows($tableLines);

        // --- 4.4 interpret rows ----------------------------------------------
        $transactions = [];
        $warnings = [];
        $running = null;
        $openingFound = false;
        $finalFound = false;
        $printedFinal = null;
        $sumDebit = '0.00';
        $sumCredit = '0.00';
        $opening = null;
        foreach ($rows as $row) {
            $tip = $this->normalise($row['tip']);
            $sold = $this->parseMoneyEn($row['sold']);
            if (str_contains($tip, 'initial')) {
                if ($sold === null) {
                    $warnings[] = 'Soldul initial nu a putut fi citit.';
                    continue;
                }
                $running = $sold;
                $opening ??= $sold;
                $openingFound = true;
                continue;
            }
            if (str_contains($tip, 'final')) {
                if ($sold === null) {
                    $warnings[] = 'Soldul final nu a putut fi citit.';
                    continue;
                }
                $printedFinal = $sold;
                $finalFound = true;
                continue;
            }
            $isCredit = str_starts_with($tip, 'c');
            $isDebit = str_starts_with($tip, 'd');
            $rulaj = $this->parseMoneyEn($row['rulaj']);
            if ((!$isCredit && !$isDebit) || $rulaj === null) {
                $warnings[] = 'Un rand nu are suma sau tip de operatie: ' . trim(str_replace("\n", ' ', $row['description']));
                continue;
            }
            if (!$openingFound) {
                $warnings[] = 'Soldul initial lipseste din extras; soldurile au fost calculate pornind de la 0.';
                $running = '0.00';
                $openingFound = true;
            }
            $amount = $this->bcAbs($rulaj);
            $closing = $isCredit ? bcadd($running, $amount, 2) : bcsub($running, $amount, 2);
            if ($sold === null || bccomp($sold, $closing, 2) !== 0) {
                $warnings[] = sprintf('Soldul tiparit (%s) nu corespunde cu soldul calculat (%s) la randul din %s.', $row['sold'] === '' ? '-' : $row['sold'], $closing, $row['dataValutei'] ?: $row['dataOperatiei']);
            }
            if ($isCredit) {
                $sumCredit = bcadd($sumCredit, $amount, 2);
            } else {
                $sumDebit = bcadd($sumDebit, $amount, 2);
            }
            $running = $closing;
            if (bccomp($amount, '0', 2) === 0) {
                continue;
            }
            $date = $this->parseDate($row['dataValutei'], ['d.m.Y']) ?? $this->parseDate($row['dataOperatiei'], ['d.m.Y']);
            if ($date === null) {
                $warnings[] = 'Rand cu suma fara data: ' . trim(str_replace("\n", ' ', $row['description']));
                continue;
            }
            $transactions[] = new PdfStatementTransaction(
                $date,
                trim($row['description']),
                $isCredit ? '0.00' : $amount,
                $isCredit ? $amount : '0.00',
                trim($row['reference']) === '' ? null : trim($row['reference']),
                null,
                null,
                null,
                $closing,
                null,
                $row['raw'],
            );
        }

        if (!$openingFound) {
            $warnings[] = 'Soldul initial lipseste din extras.';
        }
        if (!$finalFound) {
            $warnings[] = 'Soldul final lipseste din extras.';
        } elseif ($mismatch = $this->closingMismatch($printedFinal, $running)) {
            $warnings[] = $mismatch;
        }
        if (!$summaryFound) {
            $warnings[] = 'Sumarul operatiilor (rulaj debitor / rulaj creditor) lipseste din extras.';
        } elseif (bccomp($sumDebit, $printedDebit ?? '0.00', 2) !== 0 || bccomp($sumCredit, $printedCredit ?? '0.00', 2) !== 0) {
            $warnings[] = sprintf('Rulajele calculate (debitor %s, creditor %s) nu corespund cu cele tiparite in extras (debitor %s, creditor %s).', $sumDebit, $sumCredit, $printedDebit ?? '-', $printedCredit ?? '-');
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
    // Table structure
    // ------------------------------------------------------------------

    /**
     * @param PdfWord[] $line
     */
    private function isHeaderFragment(array $line): bool
    {
        if ($line === []) {
            return false;
        }
        foreach ($line as $w) {
            if (!in_array(trim($w->text), self::HEADER_VOCAB, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param PdfWord[] $band
     * @return float[]
     */
    private function boundaries(array $band): array
    {
        $valutei = $curs = $ref = $tip = $rulaj = $sold = null;
        $operatie = [];
        foreach ($band as $w) {
            $t = $this->normalise($w->text);
            if ($valutei === null && $t === 'valutei') {
                $valutei = $w;
            } elseif (rtrim($t, ':') === 'operatie') {
                $operatie[] = $w;
            } elseif ($curs === null && str_starts_with($t, 'curs')) {
                $curs = $w;
            } elseif ($ref === null && str_starts_with($t, 'ref')) {
                $ref = $w;
            } elseif ($tip === null && $t === 'tip') {
                $tip = $w;
            } elseif ($rulaj === null && $t === 'rulaj') {
                $rulaj = $w;
            } elseif ($sold === null && $t === 'sold') {
                $sold = $w;
            }
        }
        usort($operatie, static fn (PdfWord $a, PdfWord $b) => $a->x0 <=> $b->x0);
        if (!$valutei || $operatie === [] || !$curs || !$ref || !$tip || !$rulaj || !$sold) {
            throw new PdfStatementNotRecognizedException('Nu am putut identifica coloanele tabelului in PDF-ul Intesa Sanpaolo. Este posibil ca formatul extrasului sa fi fost modificat.');
        }
        $tipRight = isset($operatie[1]) ? max($tip->x1, $operatie[1]->x1) : $tip->x1;

        return [
            -INF,
            $valutei->x0 - 8.0,
            ($valutei->x0 + $operatie[0]->x0) / 2,
            $curs->x0 - 8.0,
            $ref->x0 - 8.0,
            $tip->x0 - 8.0,
            ($tipRight + $rulaj->x0) / 2,
            ($rulaj->x1 + $sold->x0) / 2,
        ];
    }

    /**
     * @param array<int, array{page: int, words: PdfWord[]}> $lines
     * @return array{0: ?string, 1: ?string, 2: bool}
     */
    private function readTurnoverSummary(array $lines, int $from): array
    {
        $count = count($lines);
        $sb = null;
        for ($i = $from; $i < $count; $i++) {
            $line = $lines[$i]['words'];
            if ($sb === null) {
                $debitor = $creditor = $net = null;
                foreach ($line as $w) {
                    $t = strtoupper($w->text);
                    if ($t === 'DEBITOR') {
                        $debitor ??= $w;
                    } elseif ($t === 'CREDITOR') {
                        $creditor ??= $w;
                    } elseif ($t === 'NET') {
                        $net ??= $w;
                    }
                }
                if ($debitor && $creditor && $net) {
                    $sb = [
                        ($debitor->x1 + $this->rulajBefore($line, $creditor)) / 2,
                        ($creditor->x1 + $this->rulajBefore($line, $net)) / 2,
                    ];
                }
                continue;
            }
            if (count($line) < 3 || !preg_match('/^\d{2}-\d{2}-\d{4}$/', $line[0]->text) || !preg_match('/^\d{2}-\d{2}-\d{4}$/', $line[1]->text)) {
                continue;
            }
            $debit = $credit = null;
            $any = false;
            foreach ($line as $w) {
                if (!preg_match(self::MONEY_RX, $w->text)) {
                    continue;
                }
                $any = true;
                $v = $this->parseMoneyEn($w->text);
                if ($w->x0 < $sb[0]) {
                    $debit = $v;
                } elseif ($w->x0 < $sb[1]) {
                    $credit = $v;
                }
            }
            if ($any) {
                return [$debit, $credit, true];
            }
        }

        return [null, null, false];
    }

    /**
     * Left edge of the right-most "RULAJ" word printed before $label (fallback: the label itself).
     *
     * @param PdfWord[] $line
     */
    private function rulajBefore(array $line, PdfWord $label): float
    {
        $best = null;
        foreach ($line as $w) {
            if (strtoupper($w->text) === 'RULAJ' && $w->x0 < $label->x0 && ($best === null || $w->x0 > $best->x0)) {
                $best = $w;
            }
        }

        return ($best ?? $label)->x0;
    }

    /**
     * Groups table lines around the dated anchor lines; a description line between two
     * anchors goes with the anchor it is vertically closest to (largest gap splits).
     *
     * @param array<int, array{cells: string[], y: float, raw: string}> $tableLines
     * @return array<int, array{dataOperatiei: string, dataValutei: string, description: string, reference: string, tip: string, rulaj: string, sold: string, raw: string[]}>
     */
    private function groupRows(array $tableLines): array
    {
        $anchors = [];
        foreach ($tableLines as $i => $line) {
            if ($this->looksLikeDate($line['cells'][0])) {
                $anchors[] = $i;
            }
        }
        if ($anchors === []) {
            return [];
        }
        $rows = [];
        $prevAnchor = null;
        foreach ($anchors as $k => $a) {
            $between = [];
            for ($i = ($prevAnchor === null ? 0 : $prevAnchor + 1); $i < $a; $i++) {
                $between[] = $i;
            }
            $forPrevious = [];
            $forCurrent = $between;
            if ($k > 0 && $between !== []) {
                $ys = [$tableLines[$prevAnchor]['y']];
                foreach ($between as $i) {
                    $ys[] = $tableLines[$i]['y'];
                }
                $ys[] = $tableLines[$a]['y'];
                $bestGap = -1.0;
                $bestPos = 0;
                for ($j = 0; $j < count($ys) - 1; $j++) {
                    $gap = abs($ys[$j + 1] - $ys[$j]);
                    if ($gap > $bestGap) {
                        $bestGap = $gap;
                        $bestPos = $j;
                    }
                }
                // entries 1..bestPos of $ys are between-lines that belong to the previous row
                $forPrevious = array_slice($between, 0, $bestPos);
                $forCurrent = array_slice($between, $bestPos);
            }
            if ($forPrevious !== [] && $rows !== []) {
                foreach ($forPrevious as $i) {
                    $this->applyCells($rows[count($rows) - 1], $tableLines[$i]);
                }
            }
            $row = [
                'dataOperatiei' => trim($tableLines[$a]['cells'][0]),
                'dataValutei' => trim($tableLines[$a]['cells'][1]),
                'description' => '',
                'reference' => '',
                'tip' => '',
                'rulaj' => '',
                'sold' => '',
                'raw' => [],
            ];
            foreach ($forCurrent as $i) {
                $this->applyCells($row, $tableLines[$i]);
            }
            $this->applyCells($row, $tableLines[$a]);
            $rows[] = $row;
            $prevAnchor = $a;
        }
        for ($i = $prevAnchor + 1; $i < count($tableLines); $i++) {
            $this->applyCells($rows[count($rows) - 1], $tableLines[$i]);
        }

        return $rows;
    }

    /**
     * @param array{dataOperatiei: string, dataValutei: string, description: string, reference: string, tip: string, rulaj: string, sold: string, raw: string[]} $row
     * @param array{cells: string[], y: float, raw: string} $line
     */
    private function applyCells(array &$row, array $line): void
    {
        $c = $line['cells'];
        if (trim($c[2]) !== '') {
            $row['description'] .= ($row['description'] === '' ? '' : "\n") . trim($c[2]);
        }
        if (trim($c[4]) !== '') {
            $row['reference'] .= trim($c[4]);
        }
        if (trim($c[5]) !== '') {
            $row['tip'] .= ($row['tip'] === '' ? '' : ' ') . trim($c[5]);
        }
        if (trim($c[6]) !== '') {
            $row['rulaj'] = trim($c[6]);
        }
        if (trim($c[7]) !== '') {
            $row['sold'] = trim($c[7]);
        }
        if ($line['raw'] !== '') {
            $row['raw'][] = $line['raw'];
        }
    }

    // ------------------------------------------------------------------
    // Metadata
    // ------------------------------------------------------------------

    /**
     * @param PdfWord[] $words
     */
    private function holderAboveAdresa(array $words): ?string
    {
        $left = array_values(array_filter($words, static fn (PdfWord $w) => $w->x0 < 300));
        $lines = $this->clusterer->cluster($left, 1.5);
        foreach ($lines as $i => $line) {
            foreach ($line as $w) {
                if (strcasecmp($w->text, 'Adresa:') === 0) {
                    if ($i === 0) {
                        return null;
                    }
                    $holder = trim($this->lineText($lines[$i - 1]));

                    return $holder === '' ? null : $holder;
                }
            }
        }

        return null;
    }

    /**
     * @param PdfWord[] $words
     */
    private function ibanAfterCodIban(array $words): ?string
    {
        $rx = '/\b[A-Z]{2}\d{2}[A-Z0-9]{10,30}\b/';
        foreach ($this->clusterer->cluster($words, 1.5) as $line) {
            $n = count($line);
            for ($i = 0; $i < $n - 1; $i++) {
                if (strcasecmp($line[$i]->text, 'COD') !== 0 || stripos($line[$i + 1]->text, 'IBAN') !== 0) {
                    continue;
                }
                for ($j = $i + 2; $j < $n; $j++) {
                    $t = trim($line[$j]->text);
                    if ($t === '') {
                        continue;
                    }
                    if (preg_match($rx, strtoupper($t), $m)) {
                        return $m[0];
                    }
                    break;
                }
            }
        }
        $w = $this->firstWordMatching($words, $rx);

        return $w ? strtoupper($w->text) : null;
    }

    /**
     * @param PdfWord[] $words
     */
    private function currencyAfterMoneda(array $words): ?string
    {
        $flat = [];
        foreach ($this->clusterer->cluster($words, 2.5) as $line) {
            foreach ($line as $w) {
                $flat[] = $w;
            }
        }
        $n = count($flat);
        for ($i = 0; $i < $n - 1; $i++) {
            if (stripos($flat[$i]->text, 'Moneda') !== 0) {
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
