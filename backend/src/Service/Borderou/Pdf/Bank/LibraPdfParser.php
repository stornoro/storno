<?php

namespace App\Service\Borderou\Pdf\Bank;

use App\Service\Borderou\Pdf\AbstractPdfStatementParser;
use App\Service\Borderou\Pdf\PdfStatement;
use App\Service\Borderou\Pdf\PdfStatementNotRecognizedException;
use App\Service\Borderou\Pdf\PdfWord;

/**
 * Libra Internet Bank bilingual statement: "Data / Descriere / Data valutei / Debit / Credit / Sold"
 * with an English sub-header, amounts 1,234.56, dates dd/MM/yyyy, opening balance printed on the
 * line above the header, closing balance on the "Sold final / End balance" row.
 */
class LibraPdfParser extends AbstractPdfStatementParser
{
    private const ISO = ['RON', 'EUR', 'USD', 'GBP', 'CHF', 'JPY', 'AUD', 'CAD', 'SEK', 'NOK', 'DKK'];

    public function getBankKey(): string
    {
        return 'libra';
    }

    public function getBankLabel(): string
    {
        return 'Libra Internet Bank';
    }

    public function score(array $page1Words): int
    {
        $t = $this->normalise(implode(' ', $page1Words));
        if (str_contains($t, 'www.librabank.ro')) {
            return 100;
        }
        $a = str_contains($t, 'libra internet bank s.a.');
        $b = str_contains($t, 'phoenix tower');
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

        $warnings = [];
        $iban = $this->firstWordMatching($first, '/\b[A-Z]{2}\d{2}[A-Z0-9]{10,30}\b/')?->text;
        if ($iban === null) {
            $warnings[] = 'IBAN-ul contului nu a fost gasit in extras.';
        }
        $holder = $this->holder($first, $h);
        $currency = $this->currency($first, $h);

        // --- table -------------------------------------------------------
        $lines = $this->linesInBand($pages, 60.0, 787.0);
        $headerIdx = null;
        foreach ($lines as $i => $w) {
            if (count($w) >= 7 && strcasecmp($w[0]->text, 'Data') === 0 && stripos($w[1]->text, 'Descriere') === 0
                && $this->hasWord($w, 'Debit') && $this->hasWord($w, 'Credit')) {
                $headerIdx = $i;
                break;
            }
        }
        if ($headerIdx === null || count($lines[$headerIdx]) !== 7) {
            throw new PdfStatementNotRecognizedException('Nu am putut identifica antetul tabelului (Data / Descriere / Debit / Credit) in PDF-ul Libra. Este posibil ca formatul extrasului sa fi fost modificat.');
        }
        $hw = $lines[$headerIdx];
        $firstData = $lastData = $debit = $credit = $sold = null;
        foreach ($hw as $w) {
            $t = strtolower($w->text);
            if ($t === 'data') {
                $firstData ??= $w;
                $lastData = $w;
            } elseif ($t === 'debit') {
                $debit ??= $w;
            } elseif ($t === 'credit') {
                $credit ??= $w;
            } elseif ($t === 'sold') {
                $sold = $w;
            }
        }
        if (!$firstData || !$lastData || !$debit || !$credit || !$sold) {
            throw new PdfStatementNotRecognizedException('Antetul tabelului Libra este incomplet.');
        }
        $bounds = [-INF, $firstData->x1 + 8.0, $lastData->x0 - 16.0, $debit->x0 - 16.0, $credit->x0 - 16.0, $sold->x0 - 12.8];

        $money = function (?string $v): ?string {
            $m = $this->parseMoneyEn($v);

            return ($m === null || bccomp($m, '0', 2) === 0) ? null : $m;
        };

        // Opening balance: the line right above the header, amount in the Credit column.
        $opening = null;
        if ($headerIdx > 0) {
            $c = $this->splitByBoundaries($lines[$headerIdx - 1], $bounds);
            $joined = $this->normalise(implode(' ', $c));
            $labelled = str_contains($joined, 'sold initial') || str_contains($joined, 'opening balance')
                || (in_array('sold', array_map(fn ($x) => $this->normalise($x), $c), true) && in_array('initial', array_map(fn ($x) => $this->normalise($x), $c), true));
            if ($labelled || ($this->parseMoneyEn($c[4] ?? '') !== null && trim($c[0]) !== '' && trim($c[1]) !== '')) {
                $opening = $this->parseMoneyEn($c[4] ?? '') ?? '0.00';
            }
        }

        $cellRows = [];
        $count = count($lines);
        for ($i = $headerIdx + 2; $i < $count; $i++) {
            $cellRows[] = $this->splitByBoundaries($lines[$i], $bounds);
        }

        $result = $this->walkDebitCreditTable($cellRows, [
            'date' => 0,
            'desc' => 1,
            'debit' => 3,
            'credit' => 4,
            'money' => $money,
            'dateFormats' => ['d/m/Y'],
            'running' => $opening,
            'skip' => fn (array $c, string $joined): bool => $this->isTableHeader($c) || $this->isSummaryRow($joined),
            'isClosing' => function (array $c): bool {
                $t = $this->normalise(implode(' ', $c));

                return str_contains($t, 'sold final') || str_contains($t, 'end balance');
            },
            'closingFrom' => fn (array $c): ?string => $this->parseMoneyEn($c[4] ?? ''),
            'breakAtClosing' => true,
            'reference' => static function (string $desc): array {
                if (!preg_match('/\(([^()]*)\)(?!.*\([^()]*\))/', $desc, $m)) {
                    return [$desc, null];
                }
                $ref = str_replace([' ', '(', ')'], '', $m[0]);
                $desc = trim(preg_replace('/\s{2,}/', ' ', str_replace($m[0], '', $desc)) ?? '');

                return [$desc, $ref === '' ? null : $ref];
            },
        ]);

        $warnings = array_merge($warnings, $result['warnings']);
        if ($mismatch = $this->closingMismatch($result['closing'], $result['running'])) {
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
            null,
            $opening,
            $result['closing'],
            null,
            null,
            $warnings,
        )];
    }

    // ------------------------------------------------------------------

    /**
     * @param string[] $c
     */
    private function isTableHeader(array $c): bool
    {
        if (count($c) < 5) {
            return false;
        }
        $n = array_map(fn (string $x) => $this->normalise($x), $c);
        $hasDebit = in_array('debit', $n, true);
        $hasCredit = in_array('credit', $n, true);

        return $hasDebit && $hasCredit && (
            (str_starts_with($n[0], 'data') && str_starts_with($n[1], 'descriere'))
            || ($n[0] === 'date' && $n[1] === 'description')
        );
    }

    private function isSummaryRow(string $joined): bool
    {
        $t = $this->normalise($joined);

        return str_contains($t, 'sold nou') || str_contains($t, 'new balance') || str_contains($t, 'rulaj');
    }

    /**
     * Holder: the line under "Generat la data:" in the top-right box (x 340..525, y 690..785 from
     * the page bottom); fallback: box words up to and including "SRL".
     *
     * @param PdfWord[] $first
     */
    private function holder(array $first, float $h): ?string
    {
        $box = array_values(array_filter($first, static fn (PdfWord $w) => $w->x0 >= 340.0 && $w->x1 <= 525.0 && $w->y0 >= $h - 785.0 && $w->y1 <= $h - 690.0));
        if ($box === []) {
            return null;
        }
        $lines = $this->clusterer->cluster($box, 1.5);
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $t = $this->normalise($this->lineText($lines[$i]));
            if (!(str_contains($t, 'generat') && str_contains($t, 'la') && str_contains($t, 'data'))) {
                continue;
            }
            for ($j = $i + 1; $j < $count; $j++) {
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
            break;
        }

        $texts = array_map(static fn (PdfWord $w) => $w->text, $box);
        $parts = [];
        foreach ($texts as $t) {
            if (strcasecmp($t, 'SRL') === 0) {
                $parts[] = 'SRL';

                return implode(' ', $parts);
            }
            $parts[] = $t;
        }

        return null;
    }

    /**
     * Currency: "Moneda:" followed by an ISO code in the top-left box (x 30..150, y 740..750),
     * then the same label anywhere on page 1, then the first ISO code in the box, else RON.
     *
     * @param PdfWord[] $first
     */
    private function currency(array $first, float $h): string
    {
        $box = array_values(array_filter($first, static fn (PdfWord $w) => $w->x0 >= 30.0 && $w->x1 <= 150.0 && $w->y0 >= $h - 750.0 && $w->y1 <= $h - 740.0));
        usort($box, static fn (PdfWord $a, PdfWord $b) => [$a->x0, $a->y0] <=> [$b->x0, $b->y0]);

        return $this->currencyAfterWord($box, 'moneda:', 15, self::ISO)
            ?? $this->currencyAfterWord($first, 'moneda:', 15, self::ISO)
            ?? $this->firstIsoCurrency($box, self::ISO)
            ?? 'RON';
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
