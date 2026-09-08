<?php

namespace App\Service\Borderou\Pdf\Bank;

use App\Service\Borderou\Pdf\PdfStatement;
use App\Service\Borderou\Pdf\PdfStatementNotRecognizedException;
use App\Service\Borderou\Pdf\PdfWord;

/**
 * CEC Bank Mobile Banking "DESCOPERIT DE CONT" layout: table
 * "Data tranzactiei / Data decontarii / Ref tranz/Nr doc / Detalii / Suma" with one signed amount
 * column (either 1.234,56 or 1,234.56); the table ends at "Total intrari" / "Total iesiri".
 */
class CecV3PdfParser extends CecV2PdfParser
{
    public function score(array $page1Words): int
    {
        $t = $this->normalise(implode(' ', $page1Words));
        $f = $this->flags($t);
        if ($f['a'] && !$f['b'] && $f['c'] && !$f['d'] && $f['e'] && !$f['f'] && !$f['g'] && $f['i']) {
            return 100;
        }

        return $this->fallbackScore($f);
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
        [$opening, $printedClosing] = $this->disponibilBalances($lines, fn (string $v) => $this->parseAmount($v));

        // --- table header ------------------------------------------------
        $headerIdx = null;
        foreach ($lines as $i => $w) {
            if (count($w) < 8) {
                continue;
            }
            $t = array_map(fn (PdfWord $x) => $this->normalise($x->text), $w);
            if ((in_array('tranzactiei', $t, true) || in_array('decontarii', $t, true)) && $this->anyStarts($t, 'doc') && in_array('suma', $t, true)) {
                $headerIdx = $i;
                break;
            }
        }
        if ($headerIdx === null) {
            throw new PdfStatementNotRecognizedException('Nu am putut identifica antetul tabelului (Data / Detalii / Suma) in PDF-ul CEC Bank. Este posibil ca formatul extrasului sa fi fost modificat sau ca banca sa nu fie inca suportata.');
        }
        $hw = $lines[$headerIdx];
        $dateWord = $this->findWord($hw, static fn (string $t) => strcasecmp($t, 'Data') === 0, true);
        $refWord = $this->findWord($hw, static fn (string $t) => stripos($t, 'Ref') === 0);
        $sumaWord = $this->findWord($hw, static fn (string $t) => stripos($t, 'Suma') === 0);
        $bounds = [-INF, $dateWord->x0 - 8.0, $refWord->x0 - 8.0, $sumaWord->x0 - 24.0];

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
        for ($i = $headerIdx + 2; $i < $n; $i++) {
            $c = $this->splitByBoundaries($lines[$i], $bounds);
            $joined = trim(implode(' ', $c));
            $d = trim($c[1] ?? '');
            $ref = trim($c[2] ?? '');
            $s = trim($c[3] ?? '');
            $normRef = $this->normalise($ref);
            $normLine = $this->normalise($joined);
            if (str_contains($normRef, 'ref tranz/nr doc') || str_contains($this->normalise($c[0] ?? ''), 'tranzactiei')) {
                continue;
            }
            if (str_contains($normLine, 'total intrari') || str_contains($normLine, 'total iesiri')) {
                break;
            }
            $date = $this->parseDateStrict($d);
            $v = $this->parseAmount($s);

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
            if ($ref !== '') {
                $current['reference'] = $ref;
            }
            $current['raw'][] = $joined;
            if ($current['amount'] === null && $v !== null && bccomp($v, '0', 2) !== 0) {
                $isCredit = bccomp($v, '0', 2) >= 0;
                $current['credit'] = $isCredit;
                $current['amount'] = $this->bcAbs($v);
                $current['closing'] = $isCredit ? bcadd($current['opening'], $current['amount'], 2) : bcsub($current['opening'], $current['amount'], 2);
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
}
