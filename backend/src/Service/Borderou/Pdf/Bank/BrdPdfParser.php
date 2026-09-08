<?php

namespace App\Service\Borderou\Pdf\Bank;

use App\Service\Borderou\Pdf\AbstractPdfStatementParser;
use App\Service\Borderou\Pdf\PdfStatement;
use App\Service\Borderou\Pdf\PdfStatementNotRecognizedException;
use App\Service\Borderou\Pdf\PdfWord;

/**
 * BRD Groupe Societe Generale, classic layout: table
 * "Data oper. / Descriere operatiune / Debit / Credit / Data valutei",
 * amounts 1.234,56, dates dd/MM/yyyy, balances in rows "Sold initial" / "Sold final".
 */
class BrdPdfParser extends AbstractPdfStatementParser
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
        $a = str_contains($t, 'brd-groupe societe generale s.a.') || str_contains($t, 'brd - groupe societe generale s.a.');
        $b = str_contains($t, 'mihalache');
        $c = str_contains($t, 'domicilierea');
        if ($a && $b && $c) {
            return 100;
        }
        if ($a || $b) {
            return 50;
        }

        return str_contains($t, 'www.brd.ro') ? 50 : 0;
    }

    public function parse(array $pages): array
    {
        $page1 = $pages[0];
        $h = $page1->height ?: 842.0;
        $headerWords = $this->wordsInBand($page1, 90.0);
        usort($headerWords, static fn (PdfWord $a, PdfWord $b) => [$a->y0, $a->x0] <=> [$b->y0, $b->x0]);

        $iban = $this->firstWordMatching($headerWords, '/\b[A-Z]{2}\d{2}[A-Z0-9]{10,30}\b/')?->text;

        // Holder box: upper-right block (PDF coords Left>=315, Right<=530, 600<=Bottom, Top<=745)
        $box = array_values(array_filter($page1->words, static fn (PdfWord $w) => $w->x0 >= 315 && $w->x1 <= 530 && ($h - $w->y0) <= 745 && ($h - $w->y1) >= 600));
        $holder = $this->holderUnderValabilFaraSemnatura($box);
        if ($holder === null) {
            $holder = $this->joinUntilLegalForm(array_map(fn (PdfWord $w) => $w->text, $box)) ?: null;
        }
        $fiscalCode = null;
        if ($label = $this->firstWordMatching($box, '/^CNP\/CUI:$/')) {
            $cands = array_filter($box, static fn (PdfWord $w) => abs($w->y1 - $label->y1) < 0.8 && $w->x0 > $label->x1);
            usort($cands, static fn (PdfWord $a, PdfWord $b) => $a->x0 <=> $b->x0);
            foreach ($cands as $w) {
                if (preg_match('/^\d+$/', trim($w->text, ':'))) {
                    $fiscalCode = trim($w->text, ':');
                    break;
                }
            }
        }

        // Currency box: left block
        $cbox = array_values(array_filter($page1->words, static fn (PdfWord $w) => $w->x0 >= 22 && $w->x1 <= 250 && ($h - $w->y0) <= 645 && ($h - $w->y1) >= 580));
        usort($cbox, static fn (PdfWord $a, PdfWord $b) => [$a->y0, $a->x0] <=> [$b->y0, $b->x0]);
        $currency = $this->currencyAfterWord($cbox, 'cont', 15, self::ISO) ?? $this->firstIsoCurrency($cbox, self::ISO) ?? 'RON';

        // --- table -------------------------------------------------------
        $lines = $this->linesInBand($pages, 90.0, 785.0);
        $headerIdx = null;
        foreach ($lines as $i => $w) {
            if (count($w) >= 8 && strcasecmp($w[0]->text, 'Data') === 0 && stripos($w[2]->text, 'Descriere') === 0
                && $this->hasWord($w, 'Debit') && $this->hasWord($w, 'Credit')) {
                $headerIdx = $i;
                break;
            }
        }
        if ($headerIdx === null || count($lines[$headerIdx]) !== 8) {
            throw new PdfStatementNotRecognizedException('Nu am putut identifica antetul tabelului (Data / Descriere / Debit / Credit) in PDF-ul BRD. Este posibil ca formatul extrasului sa fi fost modificat.');
        }
        $hw = $lines[$headerIdx];
        $data = $this->findWord($hw, 'Data');
        $debit = $this->findWord($hw, 'Debit');
        $credit = $this->findWord($hw, 'Credit');
        $data2 = $this->findWord($hw, 'Data', true);
        $bounds = [-INF, $data->x1 + 8.0, $debit->x0 - 24.0, $credit->x0 - 24.0, $data2->x0 - 12.8];

        $cellRows = [];
        for ($i = $headerIdx + 2; $i < count($lines); $i++) {
            $cellRows[] = $this->splitByBoundaries($lines[$i], $bounds);
        }

        $isHeader = function (array $c): bool {
            $l = array_map(fn ($x) => $this->normalise($x), $c);
            if (count($l) >= 5 && str_starts_with($l[0], 'data oper') && str_starts_with($l[1], 'descriere') && in_array('debit', $l, true) && in_array('credit', $l, true)) {
                return true;
            }

            return count($l) >= 5 && $l[0] === 'trans.date' && $l[1] === 'transaction description' && $l[4] === 'value date';
        };

        $result = $this->walkDebitCreditTable($cellRows, [
            'money' => fn (?string $v) => $this->parseMoneyRo($v),
            'dateFormats' => ['d/m/Y'],
            'isHeader' => $isHeader,
            'skip' => function (array $c): bool {
                $d = $this->normalise($c[1] ?? '');
                foreach (['total debit', 'card:', 'posesor:'] as $needle) {
                    if (str_contains($d, $needle)) {
                        return true;
                    }
                }

                return false;
            },
            'isClosing' => function (array $c): bool {
                $d = $this->normalise($c[1] ?? '');
                foreach (['sold final', 'end balance', 'sold disponibil', 'available'] as $needle) {
                    if (str_contains($d, $needle)) {
                        return true;
                    }
                }

                return false;
            },
            'closingFrom' => function (array $c): ?string {
                $v = $this->parseMoneyRo($c[3] ?? '');
                if ($v === null || bccomp($v, '0', 2) === 0) {
                    $v = $this->parseMoneyRo($c[2] ?? '') ?? $v;
                }

                return $v;
            },
            'breakAtClosing' => true,
            'isOpening' => fn (array $c) => str_starts_with($this->normalise($c[1] ?? ''), 'sold initial')
                || ($this->parseMoneyRo($c[3] ?? '') !== null && trim($c[1] ?? '') === '' && trim($c[2] ?? '') === ''),
            'reference' => static function (string $desc): array {
                $ref = null;
                $rx = '/\b(?:OPH\s*[A-Z0-9]+|OPT\s*[A-Z0-9]+|OP\s*\d+(?:\/\d+)?|NC\s*\d+)\b/i';
                if (preg_match($rx, $desc, $m)) {
                    $ref = preg_replace('/\s+/', '', $m[0]);
                }
                $desc = preg_replace($rx, '', $desc) ?? $desc;

                return [trim(preg_replace('/\s{2,}/', ' ', $desc) ?? $desc), $ref];
            },
        ]);

        $warnings = $result['warnings'];
        if ($mismatch = $this->closingMismatch($result['closing'], $result['running'])) {
            $warnings[] = $mismatch;
        }
        if ($result['transactions'] === []) {
            $warnings[] = 'Nu a fost gasita nicio tranzactie in extras.';
        }
        $opening = null;
        if ($result['transactions'] !== []) {
            $first = $result['transactions'][0];
            $opening = bcsub(bcadd($first->balance ?? '0.00', $first->debit, 2), $first->credit, 2);
        }

        return [new PdfStatement($this->getBankKey(), $this->getBankLabel(), $iban, $currency, $result['transactions'], $holder, $fiscalCode, $opening, $result['closing'], null, null, $warnings)];
    }

    /**
     * @param PdfWord[] $words
     */
    private function hasWord(array $words, string $text): bool
    {
        return $this->findWord($words, $text) !== null;
    }

    /**
     * @param PdfWord[] $words
     */
    private function findWord(array $words, string $text, bool $last = false): ?PdfWord
    {
        $found = null;
        foreach ($words as $w) {
            if (strcasecmp($w->text, $text) === 0) {
                $found = $w;
                if (!$last) {
                    return $found;
                }
            }
        }

        return $found;
    }

    /**
     * Holder = the line under "valabil fara semnatura" (or "semnatura ... bancii").
     *
     * @param PdfWord[] $box
     */
    protected function holderUnderValabilFaraSemnatura(array $box): ?string
    {
        $lines = $this->clusterer->cluster($box, 1.5);
        foreach ($lines as $i => $line) {
            $t = $this->normalise($this->lineText($line));
            $hit = (str_contains($t, 'valabil') && str_contains($t, 'fara') && str_contains($t, 'semnatura'))
                || (str_contains($t, 'semnatura') && str_contains($t, 'bancii'));
            if (!$hit) {
                continue;
            }
            for ($j = $i + 1; $j < count($lines); $j++) {
                $parts = array_values(array_filter(array_map(static fn (PdfWord $w) => trim($w->text, ':'), $lines[$j]), static fn ($s) => $s !== ''));
                if ($parts !== []) {
                    return implode(' ', $parts);
                }
            }
        }

        return null;
    }

    /**
     * @param string[] $tokens
     */
    protected function joinUntilLegalForm(array $tokens): string
    {
        $out = [];
        foreach ($tokens as $t) {
            $t = trim((string) $t);
            if ($t === '') {
                continue;
            }
            $out[] = $t;
            if (in_array(strtoupper($t), ['SRL', 'S.R.L.', 'S.R.L', 'SRL-D', 'SA', 'S.A.', 'PFA', 'P.F.A.', 'SNC', 'SCS', 'SCA'], true)) {
                break;
            }
        }

        return implode(' ', $out);
    }
}
