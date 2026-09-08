<?php

namespace App\Service\Borderou\Pdf\Bank;

use App\Service\Borderou\Pdf\PdfStatement;
use App\Service\Borderou\Pdf\PdfStatementNotRecognizedException;
use App\Service\Borderou\Pdf\PdfWord;

/**
 * ING Bank Romania, newer layout: no "Rezumat" block; summary "Sold initial / Sold final / Perioada"
 * followed by a values line; table "Data procesarii / Beneficiar ... / Debitari / Creditari / Sold";
 * IBAN printed in spaced 4-character groups; "NAME | CUI 123" under the IBAN.
 */
class IngV2PdfParser extends IngPdfParser
{
    private const IBAN_RX = '/\bRO\d{2}[ \t\x{00A0}]+INGB(?:[ \t\x{00A0}]+[A-Z0-9]{4}){4}\b/iu';
    private const IBAN_LABEL_RX = '/\b(IBAN|cont\s+curent|account|current\s+account|principal)\b/iu';

    public function score(array $page1Words): int
    {
        $t = $this->normalise(implode(' ', $page1Words));
        $r = str_contains($t, 'rezumat') || str_contains($t, 'summary');
        $a = str_contains($t, 'ing bank n.v. amsterdam');
        $b = str_contains($t, 'aviator popisteanu');
        $c = str_contains($t, 'numar extras cont') || str_contains($t, 'statement no');
        if ($a && $b && !$c && !$r) {
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
        $warnings = [];

        // Header word set: right half of page 1, lines above the "Sold initial" anchor.
        $rightWords = array_values(array_filter($this->wordsInBand($page1, 90.0), static fn (PdfWord $w) => $w->x1 > 340.0));
        $headerWords = [];
        foreach ($this->clusterer->cluster($rightWords, 2.5) as $line) {
            $t = $this->normalise($this->lineText($line));
            if ((str_contains($t, 'sold') && str_contains($t, 'initial')) || str_contains($t, 'opening balance') || str_contains($t, 'initial balance')) {
                break;
            }
            foreach ($line as $w) {
                $headerWords[] = $w;
            }
        }

        $headerText = implode(' ', array_map(static fn (PdfWord $w) => $w->text, $headerWords));
        $iban = $this->bestSpacedIban($headerText);
        if ($iban === null) {
            $warnings[] = 'Nu a fost gasit IBAN-ul contului in extras.';
        }
        [$holder, $fiscalCode] = $this->nameAndFiscalUnderIban($headerWords);
        if ($fiscalCode === null) {
            $fiscalCode = $this->digitsRightOfLabel($headerWords, '/^CUI$/');
        }
        $currency = $this->firstIsoCurrency($headerWords, self::ISO) ?? 'RON';

        // --- table -------------------------------------------------------
        $lines = $this->linesInBand($pages, 60.0, 735.0);

        // Summary: "Sold initial ... Sold final ... Perioada" then a values line (token 0 / token 3).
        $opening = null;
        $printedClosing = null;
        foreach ($lines as $i => $w) {
            if (count($w) < 4) {
                continue;
            }
            $t = [];
            foreach ($w as $x) {
                $s = trim($this->normalise($x->text), ":-\u{2013}\u{2014}");
                if ($s !== '') {
                    $t[] = $s;
                }
            }
            $ok = ($this->seq($t, ['sold', 'initial']) || $this->seq($t, ['opening', 'balance']) || $this->seq($t, ['initial', 'balance']))
                && ($this->anyStarts($t, 'final') || $this->seq($t, ['closing', 'balance']) || $this->seq($t, ['ending', 'balance']))
                && ($this->anyStarts($t, 'perioada') || in_array(true, array_map(static fn (string $s) => str_contains($s, 'date'), $t), true) || $this->seq($t, ['statement', 'period']));
            if (!$ok) {
                continue;
            }
            $values = $lines[$i + 1] ?? [];
            if (isset($values[0])) {
                $opening = $this->parseMoneyEn($values[0]->text);
            }
            if (isset($values[3])) {
                $printedClosing = $this->parseMoneyEn($values[3]->text);
            }
            break;
        }

        $headerIdx = null;
        foreach ($lines as $i => $w) {
            if (count($w) < 7) {
                continue;
            }
            $t = array_map(fn (PdfWord $x) => $this->normalise($x->text), $w);
            $ok = ($this->seq($t, ['data', 'procesarii']) || $this->seq($t, ['book', 'date']))
                && ($this->anyStarts($t, 'beneficiar') || in_array(true, array_map(static fn (string $s) => str_contains($s, 'counterparty'), $t), true))
                && (in_array('debitari', $t, true) || in_array('debit', $t, true))
                && (in_array('creditari', $t, true) || in_array('credit', $t, true));
            if ($ok) {
                $headerIdx = $i;
                break;
            }
        }
        if ($headerIdx === null) {
            throw new PdfStatementNotRecognizedException('Nu am putut identifica antetul tabelului (Data procesarii / Beneficiar / Debitari / Creditari) in PDF-ul ING. Este posibil ca formatul extrasului sa fi fost modificat sau ca banca sa nu fie inca suportata.');
        }
        $hw = $lines[$headerIdx];
        $date = $this->findWord($hw, static fn (string $t) => strcasecmp($t, 'procesarii') === 0 || strcasecmp($t, 'date') === 0, true);
        $debit = $this->findWord($hw, static fn (string $t) => stripos($t, 'Debit') === 0);
        $credit = $this->findWord($hw, static fn (string $t) => stripos($t, 'Credit') === 0);
        $sold = $this->findWord($hw, static fn (string $t) => strcasecmp($t, 'Sold') === 0 || stripos($t, 'Balance') === 0, true);
        $bounds = [-INF, $date->x1 + 8.0, $debit->x0 - 32.0, $credit->x0 - 32.0, $sold->x0 - 12.8];

        $result = $this->walkIngTable($lines, $headerIdx + 2, $bounds, $opening);
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

    /**
     * Spaced "RO12 INGB 0000 0000 0000 0000" candidates, mod-97 validated and scored.
     */
    private function bestSpacedIban(string $input): ?string
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
            if (substr($iban, 4, 4) === 'INGB') {
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

    private function ibanChecksumValid(string $iban): bool
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
     * "NAME | CUI 123" on the line under the spaced IBAN.
     *
     * @param PdfWord[] $headerWords
     * @return array{0: ?string, 1: ?string}
     */
    private function nameAndFiscalUnderIban(array $headerWords): array
    {
        $lines = $this->clusterer->cluster($headerWords, 1.5);
        $n = count($lines);
        for ($i = 0; $i < $n; $i++) {
            if (!preg_match(self::IBAN_RX, $this->lineText($lines[$i]))) {
                continue;
            }
            for ($j = $i + 1; $j < $n; $j++) {
                $tokens = [];
                foreach ($lines[$j] as $w) {
                    $s = trim($w->text);
                    if ($s !== '') {
                        $tokens[] = $s;
                    }
                }
                if ($tokens === []) {
                    continue;
                }
                $p = null;
                foreach ($tokens as $k => $tok) {
                    if (str_contains($tok, '|')) {
                        $p = $k;
                        break;
                    }
                }
                if ($p === null || $p === 0) {
                    return [null, null];
                }
                $pipe = strpos($tokens[$p], '|');
                $leftPart = trim(substr($tokens[$p], 0, $pipe));
                $rightPart = trim(substr($tokens[$p], $pipe + 1));
                $nameTokens = array_slice($tokens, 0, $p);
                if ($leftPart !== '') {
                    $nameTokens[] = $leftPart;
                }
                $nameTokens = array_filter($nameTokens, static fn (string $t) => $t !== '|');
                $holder = trim(implode(' ', $nameTokens));
                $fiscalTokens = array_slice($tokens, $p + 1);
                if ($rightPart !== '') {
                    array_unshift($fiscalTokens, $rightPart);
                }
                $fiscal = '';
                foreach ($fiscalTokens as $t) {
                    if ($t === '|' || in_array(strtoupper($t), ['CUI', 'CIF', 'CNP'], true)) {
                        continue;
                    }
                    $fiscal .= preg_replace('/[^A-Za-z0-9]/', '', $t) ?? '';
                }

                return [$holder === '' ? null : $holder, $fiscal === '' ? null : $fiscal];
            }
            break;
        }

        return [null, null];
    }
}
