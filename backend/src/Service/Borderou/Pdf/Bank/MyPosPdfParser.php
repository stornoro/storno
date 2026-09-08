<?php

namespace App\Service\Borderou\Pdf\Bank;

use App\Service\Borderou\Pdf\AbstractPdfStatementParser;
use App\Service\Borderou\Pdf\PdfPage;
use App\Service\Borderou\Pdf\PdfStatement;
use App\Service\Borderou\Pdf\PdfStatementNotRecognizedException;
use App\Service\Borderou\Pdf\PdfStatementTransaction;
use App\Service\Borderou\Pdf\PdfWord;

/**
 * myPOS account statement (Romanian or English labels): table
 * "Data valutei / Comandați prin / Bacșis / Descriere / Curs de schimb / Debit / Credit",
 * newest first, value date "dd.MM.yyyy HH:mm", English-style amounts.
 */
class MyPosPdfParser extends AbstractPdfStatementParser
{
    use NeoBankHelpersTrait;

    public function getBankKey(): string
    {
        return 'mypos';
    }

    public function getBankLabel(): string
    {
        return 'myPOS';
    }

    public function score(array $page1Words): int
    {
        $t = $this->normalise(implode(' ', $page1Words));
        $a = str_contains($t, 'mypos ltd') || str_contains($t, 'mypos payments ltd');
        $b = str_contains($t, "12 st. stephen\u{2019}s green, dublin") || str_contains($t, "12 st. stephen's green, dublin") || str_contains($t, '32 london bridge street');
        $c = str_contains($t, '700880,') || str_contains($t, '10630670');
        if ($a && $b && $c) {
            return 100;
        }

        return ($a || $b) ? 50 : 0;
    }

    public function parse(array $pages): array
    {
        $page1 = $pages[0];
        $words = $this->page1Words($page1);

        $iban = $this->firstWordMatching($words, '/\b[A-Z]{2}\d{2}[A-Z0-9]{10,30}\b/')?->text;
        $holder = $this->holder($page1);
        $currency = $this->currency($words);

        // --- table -------------------------------------------------------
        $lines = $this->linesInBand($pages, 60.0, 730.0, 4.5);
        $headerIdx = null;
        $cut = null;
        foreach ($lines as $i => $line) {
            if (!$this->isHeaderLine($line)) {
                continue;
            }
            $cut = $this->cutWords($line);
            if ($cut !== null) {
                $headerIdx = $i;
                break;
            }
        }
        if ($headerIdx === null || $cut === null) {
            throw new PdfStatementNotRecognizedException('Nu am putut identifica antetul tabelului (Data valutei / Comandati prin / Bacsis / Descriere / Curs de schimb / Debit / Credit) in PDF-ul myPOS. Este posibil ca formatul extrasului sa fi fost modificat sau ca banca sa nu fie inca suportata.');
        }
        [$w1, $w2, $w3, $w4, $w5, $w6] = $cut;
        $bounds = [-INF, $w1->x0 - 8.0, $w2->x0 - 8.0, $w3->x0 - 8.0, $w4->x0 - 8.0, $w5->x0 - 16.0, $w6->x0 - 24.0];
        $header = $lines[$headerIdx];

        [$opening, $printedClosing] = $this->balances($lines, $headerIdx);

        // --- rows (newest first) ------------------------------------------
        $rows = [];
        $current = null;
        $flush = static function () use (&$current, &$rows): void {
            if ($current !== null) {
                $rows[] = $current;
                $current = null;
            }
        };
        $count = count($lines);
        for ($i = $headerIdx + 1; $i < $count; $i++) {
            $line = $lines[$i];
            if ($this->sameHeader($line, $header)) {
                continue; // repeated header on a following page
            }
            $cells = $this->splitByBoundaries($line, $bounds);
            $joined = trim(implode(' ', $cells));
            $date = $this->parseDateStrict($cells[0]);
            $debit = $this->parseMoneyEn($cells[5]);
            $credit = $this->parseMoneyEn($cells[6]);
            $hasDebit = $this->nonZero($debit);
            $hasCredit = $this->nonZero($credit);
            if ($date !== null && ($hasDebit xor $hasCredit)) {
                $flush();
                $current = [
                    'date' => $date,
                    'credit' => $hasCredit,
                    'amount' => $this->bcAbs($hasCredit ? $credit : $debit),
                    'description' => trim($cells[3]),
                    'raw' => [$joined],
                ];
                continue;
            }
            if ($current !== null) {
                if (trim($cells[3]) !== '') {
                    $current['description'] .= ' ' . trim($cells[3]);
                }
                $current['raw'][] = $joined;
            }
        }
        $flush();

        // --- post-processing: oldest first, running balance from the opening ----
        $rows = array_reverse($rows);
        $warnings = [];
        if ($opening === null) {
            $warnings[] = 'Soldul initial nu a fost gasit in extras; soldurile tranzactiilor pornesc de la 0.';
        }
        $running = $opening ?? '0.00';
        $transactions = [];
        foreach ($rows as $row) {
            $running = $row['credit'] ? bcadd($running, $row['amount'], 2) : bcsub($running, $row['amount'], 2);
            $description = trim(preg_replace('/\s{2,}/', ' ', $row['description']) ?? '');
            $transactions[] = new PdfStatementTransaction(
                $row['date'],
                $description,
                $row['credit'] ? '0.00' : $row['amount'],
                $row['credit'] ? $row['amount'] : '0.00',
                null,
                null,
                $this->counterpartyFromDescription($description),
                null,
                $running,
                $currency,
                $row['raw'],
            );
        }
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
            $printedClosing ?? $running,
            null,
            null,
            $warnings,
        )];
    }

    /**
     * @param PdfWord[] $line
     */
    private function isHeaderLine(array $line): bool
    {
        if (count($line) < 8) {
            return false;
        }
        $t = $this->normTokens($line);
        if (str_starts_with($t[0], 'data')) {
            return true;
        }

        return str_starts_with($t[0], 'value')
            && (in_array('descriere', $t, true) || in_array('description', $t, true))
            && in_array('debit', $t, true) && in_array('credit', $t, true);
    }

    /**
     * The six header words the column cut-points hang on; null when any is missing.
     *
     * @param PdfWord[] $line
     * @return array{0: PdfWord, 1: PdfWord, 2: PdfWord, 3: PdfWord, 4: PdfWord, 5: PdfWord}|null
     */
    private function cutWords(array $line): ?array
    {
        $w1 = $this->findWord($line, ['comandati', 'ordina', 'order']);
        $w2 = $this->findWord($line, ['bacsis', 'tip', 'type']);
        $w3 = $this->findWord($line, ['descriere', 'description']);
        $w4 = $this->findWord($line, ['curs', 'comandat', 'exch.']);
        $w5 = $this->findWord($line, ['debit']);
        $w6 = $this->findWord($line, ['credit'], true);
        if (!$w1 || !$w2 || !$w3 || !$w4 || !$w5 || !$w6) {
            return null;
        }

        return [$w1, $w2, $w3, $w4, $w5, $w6];
    }

    /**
     * @param PdfWord[] $line
     * @param PdfWord[] $header
     */
    private function sameHeader(array $line, array $header): bool
    {
        if (count($line) < 3 || count($header) < 3) {
            return false;
        }

        return strcasecmp($this->pdfText($line[0]->text), $this->pdfText($header[0]->text)) === 0
            && strcasecmp($this->pdfText($line[2]->text), $this->pdfText($header[2]->text)) === 0;
    }

    /**
     * Spec: "Sold la deschidere:" / "Sold la închidere:" are looked up in flat lines 8..11; when
     * that window misses, every line above the table header is tried with the strict labels.
     *
     * @param array<int, PdfWord[]> $lines
     * @return array{0: ?string, 1: ?string}
     */
    private function balances(array $lines, int $headerIdx): array
    {
        $money = fn (string $v) => $this->parseMoneyEn($v);
        $opening = null;
        $closing = null;
        foreach (range(8, 11) as $i) {
            if (!isset($lines[$i])) {
                continue;
            }
            $line = $lines[$i];
            $t = $this->normalise($this->lineText($line));
            if ($opening === null) {
                $isOpening = (str_contains($t, 'sold') && str_contains($t, 'la') && str_contains($t, 'deschidere:'))
                    || str_contains($t, 'opening balance') || str_contains($t, 'sold') || str_contains($t, 'deschidere:');
                if ($isOpening) {
                    $v = $this->rightmostMoney($line, $money);
                    if ($v !== null) {
                        $opening = $v;
                        continue;
                    }
                }
            }
            $v = $this->closingOf($line, $money, true);
            if ($v !== null) {
                $closing = $v;
                break;
            }
        }
        if ($opening === null || $closing === null) {
            for ($i = 0; $i < $headerIdx; $i++) {
                $line = $lines[$i];
                $t = $this->normalise($this->lineText($line));
                if ($opening === null && ((str_contains($t, 'sold') && str_contains($t, 'deschidere')) || str_contains($t, 'opening balance'))) {
                    $opening = $this->rightmostMoney($line, $money);
                    continue;
                }
                if ($closing === null) {
                    $closing = $this->closingOf($line, $money, false);
                }
            }
        }

        return [$opening, $closing];
    }

    /**
     * @param PdfWord[] $line
     */
    private function closingOf(array $line, callable $money, bool $loose): ?string
    {
        if ($this->hasKnownToken($line, 'Sold la închidere:') || $this->hasKnownToken($line, 'End balance')) {
            return $this->rightmostMoney($line, $money);
        }
        if ($loose && count($line) > 4 && $money($line[4]->text) !== null && trim($line[0]->text) !== '' && trim($line[1]->text) !== '') {
            return $money($line[4]->text);
        }
        $t = $this->normalise($this->lineText($line));
        if (str_contains($t, 'sold') || str_contains($t, 'final') || str_contains($t, 'closing')) {
            if (!$loose && !(str_contains($t, 'final') || str_contains($t, 'closing') || str_contains($t, 'inchidere'))) {
                return null;
            }

            return $this->rightmostMoney($line, $money);
        }

        return null;
    }

    /**
     * "Nume: EXEMPLU SRL" inside the fixed page-1 box; the value stops at the next label
     * ("IBAN:") so a same-line IBAN does not become part of the name.
     */
    private function holder(PdfPage $page1): ?string
    {
        $box = $this->boxWords($page1, 30.0, 510.0, 720.0, 670.0);
        $lines = $this->clusterer->cluster($box, 1.5);
        foreach (['nume:', 'name:'] as $label) {
            foreach ($lines as $line) {
                if (count($line) < 3) {
                    continue;
                }
                foreach ($line as $k => $w) {
                    if (stripos($this->pdfText($w->text), $label) !== 0) {
                        continue;
                    }
                    $name = $this->valueWords(array_slice($line, $k + 1));
                    if ($name !== '') {
                        return $name;
                    }
                    break;
                }
            }
        }
        foreach ($lines as $line) {
            foreach ($line as $k => $w) {
                $t = strtolower($this->pdfText($w->text));
                if ($t === 'nume:' || $t === 'name:') {
                    $name = $this->valueWords(array_slice($line, $k + 1));
                    if ($name !== '') {
                        return $name;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param PdfWord[] $words
     */
    private function valueWords(array $words): string
    {
        $parts = [];
        foreach ($words as $i => $w) {
            $text = $this->pdfText($w->text);
            if ($i > 0 && (str_ends_with($text, ':') || preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $text))) {
                break;
            }
            $text = trim(str_replace(':', '', $text));
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return trim(implode(' ', $parts));
    }

    /**
     * "Moneda: RON" / "Currency: EUR": ISO code on that line, else on the next one.
     *
     * @param PdfWord[] $words
     */
    private function currency(array $words): string
    {
        $lines = $this->clusterer->cluster($words, 1.5);
        foreach ($lines as $i => $line) {
            $hit = false;
            foreach ($line as $w) {
                $t = strtolower(trim($this->pdfText($w->text), ':'));
                if ($t === 'moneda' || $t === 'currency') {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                continue;
            }
            foreach ([$line, $lines[$i + 1] ?? []] as $candidate) {
                foreach ($candidate as $w) {
                    $iso = $this->isoToken($this->pdfText($w->text), self::NEO_ISO);
                    if ($iso !== null) {
                        return $iso;
                    }
                }
            }
        }

        return $this->firstIsoCurrency($words, self::NEO_ISO) ?? 'RON';
    }

    /**
     * NormalizePdfText: ligatures expanded, NBSP to space, zero-width space removed, trimmed.
     */
    private function pdfText(string $text): string
    {
        return trim(str_replace(["\u{FB01}", "\u{FB02}", "\u{00A0}", "\u{200B}"], ['fi', 'fl', ' ', ''], $text));
    }

    /**
     * @param PdfWord[] $words
     * @param string[] $alternatives normalised texts
     */
    private function findWord(array $words, array $alternatives, bool $last = false): ?PdfWord
    {
        $found = null;
        foreach ($words as $w) {
            if (in_array($this->normalise($this->pdfText($w->text)), $alternatives, true)) {
                $found = $w;
                if (!$last) {
                    break;
                }
            }
        }

        return $found;
    }
}
