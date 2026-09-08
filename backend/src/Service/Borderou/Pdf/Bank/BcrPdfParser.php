<?php

namespace App\Service\Borderou\Pdf\Bank;

use App\Service\Borderou\Pdf\AbstractPdfStatementParser;
use App\Service\Borderou\Pdf\PdfStatement;
use App\Service\Borderou\Pdf\PdfStatementNotRecognizedException;
use App\Service\Borderou\Pdf\PdfWord;

/**
 * BCR / George "EXTRAS DE CONT" (JasperReports layout): per-day blocks with
 * "Data: dd-MM-yyyy ... Sold contabil initial", table
 * "Data operatiunii / Explicatie / Referinta Oper. / Debit / Credit", amounts 1.234,56.
 * Also understands the English variant ("Operation Date / Explanation", amounts 1,234.56).
 */
class BcrPdfParser extends AbstractPdfStatementParser
{
    private const ISO = ['RON', 'EUR', 'USD', 'GBP', 'CHF', 'JPY', 'AUD', 'CAD', 'SEK', 'NOK', 'DKK', 'HUF', 'PLN'];
    private const HOLDER_STOP = ['cic', 'cui', 'client', 'uic'];
    private const FINAL_TOKENS = ['sold final', 'end balance', 'sold contabil final la', 'final amount balance at'];
    private const SUMMARY_TOKENS = [
        'sold nou', 'new balance', 'rulaj', 'tranzactii finalizate', 'sold contabil initial', 'sold contabil final',
        'total tranzactii finalizate', 'alte sume blocate', 'plafon disponibil din linii de credit', 'initial amount balance',
        'final transactions', 'final amount balance', 'lending limit available', 'total transactions completed',
    ];

    public function getBankKey(): string
    {
        return 'bcr';
    }

    public function getBankLabel(): string
    {
        return 'Banca Comerciala Romana';
    }

    public function score(array $page1Words): int
    {
        $t = $this->normalise(implode(' ', $page1Words));
        if (str_contains($t, 'www.bcr.ro')) {
            return 100;
        }
        $a = str_contains($t, 'banca comerciala romana s.a.');
        $b = str_contains($t, 'cladirea the bridge');
        if ($a && $b) {
            return 100;
        }

        return ($a || $b) ? 50 : 0;
    }

    public function parse(array $pages): array
    {
        $page1 = $pages[0];
        $words = $page1->words;
        usort($words, static fn (PdfWord $a, PdfWord $b) => [$a->y0, $a->x0] <=> [$b->y0, $b->x0]);
        $lines1 = $this->clusterer->cluster($words, 2.5);

        $english = false;
        foreach ($lines1 as $line) {
            $t = $this->normalise($this->lineText($line));
            if (str_contains($t, 'product currency') || str_contains($t, 'initial amount balance')) {
                $english = true;
                break;
            }
        }

        $iban = $this->extractIban(implode(' ', array_map(fn (PdfWord $w) => $w->text, $words)));
        $holder = $this->extractHolder($lines1, $english);
        $fiscalCode = $this->extractFiscalCode($lines1, $words, $english);
        $currency = $this->extractCurrencyBcr($lines1, $words);

        $money = fn (?string $v) => $english ? $this->parseMoneyEnStrict($v) : $this->parseMoneyRo($v);

        // --- table -------------------------------------------------------
        $lines = $this->linesInBand($pages, 35.0, 787.0);
        $headerIdx = null;
        foreach ($lines as $i => $l) {
            if (count($l) < 7) {
                continue;
            }
            $ok = $english
                ? (stripos($l[0]->text, 'Operation') === 0 && strcasecmp($l[1]->text, 'Date') === 0 && stripos($l[2]->text, 'Explanation') === 0)
                : (stripos($l[1]->text, 'operatiunii') === 0 && stripos($l[2]->text, 'Explicatie') === 0);
            if ($ok && $this->hasWord($l, 'Debit') && $this->hasWord($l, 'Credit')) {
                $headerIdx = $i;
                break;
            }
        }
        if ($headerIdx === null || count($lines[$headerIdx]) !== 7) {
            throw new PdfStatementNotRecognizedException('Nu am putut identifica antetul tabelului (Data / Explicatie / Referinta Oper. / Debit / Credit) in PDF-ul BCR. Este posibil ca formatul extrasului sa fi fost modificat.');
        }
        $bounds = $this->columnBoundariesBcr($lines[$headerIdx], $english);

        $transactions = [];
        $warnings = [];
        $running = null;
        $printedFinal = null;

        // Opening balance: the line right before the first header ("Data: dd-MM-yyyy ... Sold contabil initial: x")
        if ($headerIdx > 0) {
            $c = $this->splitByBoundaries($lines[$headerIdx - 1], $bounds);
            if ($this->isOpeningCell($c, $money)) {
                $running = $money($c[4]) ?? '0.00';
            }
        }
        if ($running === null) {
            // Robustness beyond the reference implementation: look for the label anywhere on page 1.
            foreach ($lines1 as $line) {
                $t = $this->lineText($line);
                if (preg_match('/sold contabil initial:?\s*(-?[\d.,]+)/i', $t, $m)) {
                    $running = $money($m[1]);
                    break;
                }
            }
        }
        $opening = $running;

        $current = null;
        $date = null;
        $flush = function () use (&$current, &$transactions): void {
            if ($current !== null) {
                $transactions[] = $current->build();
                $current = null;
            }
        };

        for ($i = $headerIdx + 3; $i < count($lines); $i++) {
            $c = $this->splitByBoundaries($lines[$i], $bounds);
            $joined = trim(implode(' ', $c));
            $norm = $this->normalise($joined);
            if ($this->isTableHeaderCells($c)) {
                continue;
            }
            if ($this->containsAny($norm, self::FINAL_TOKENS)) {
                $printedFinal = $money($c[4]);
                $flush();
                break;
            }
            if ($english && str_contains($norm, 'final amount balance') && !str_contains($norm, 'final amount balance at')) {
                $v = $money($c[4]);
                if ($running !== null && $v !== null && bccomp($v, $running, 2) !== 0) {
                    $warnings[] = sprintf('Sold zilnic tiparit (%s) diferit de cel calculat (%s).', $v, $running);
                }
                continue;
            }
            if ($this->containsAny($norm, self::SUMMARY_TOKENS)) {
                continue;
            }
            $dateCell = mb_substr(trim($c[0]), 0, 10);
            $parsedDate = $english ? $this->parseDate($dateCell, ['m/d/Y']) : ($this->parseDateStrict($dateCell) ? $this->parseDate($dateCell, ['d-m-Y']) : null);
            $debit = $money($c[3]);
            $credit = $money($c[4]);
            $hasDebit = $debit !== null && bccomp($debit, '0', 2) !== 0;
            $hasCredit = $credit !== null && bccomp($credit, '0', 2) !== 0;
            if ($hasDebit xor $hasCredit) {
                $flush();
                if ($parsedDate) {
                    $date = $parsedDate;
                }
                if ($date === null) {
                    $warnings[] = 'Rand cu suma fara data: ' . $joined;
                    continue;
                }
                $amount = $this->bcAbs($hasCredit ? $credit : $debit);
                $isCredit = $hasCredit;
                if (bccomp($hasCredit ? $credit : $debit, '0', 2) < 0) {
                    $isCredit = !$isCredit; // negative cell = reversal
                }
                $open = $running ?? '0.00';
                $closing = $isCredit ? bcadd($open, $amount, 2) : bcsub($open, $amount, 2);
                $running = $closing;
                $current = new BcrRowBuilder($date, trim($c[1]), trim($c[2]), $isCredit ? '0.00' : $amount, $isCredit ? $amount : '0.00', $closing, $joined);
                continue;
            }
            if ($current !== null) {
                $current->continueWith(trim($c[1]), trim($c[2]), $joined);
            }
        }
        $flush();

        if ($printedFinal === null) {
            $warnings[] = 'Soldul final nu a fost gasit in extras; tranzactiile nu au putut fi validate.';
        } elseif ($mismatch = $this->closingMismatch($printedFinal, $running)) {
            $warnings[] = $mismatch;
        }
        if ($transactions === []) {
            $warnings[] = 'Nu a fost gasita nicio tranzactie in extras.';
        }

        return [new PdfStatement($this->getBankKey(), $this->getBankLabel(), $iban, $currency, $transactions, $holder, $fiscalCode, $opening, $printedFinal, null, null, $warnings)];
    }

    /**
     * English layout money: strictly "1,234.56" / "1234.56" with optional leading minus.
     */
    private function parseMoneyEnStrict(?string $s): ?string
    {
        $s = trim((string) $s);
        if (!preg_match('/^-?\d{1,3}(,\d{3})*\.\d{2}$/', $s) && !preg_match('/^-?\d+\.\d{2}$/', $s)) {
            return null;
        }

        return number_format((float) str_replace(',', '', $s), 2, '.', '');
    }

    /**
     * @param PdfWord[] $header
     * @return float[]
     */
    private function columnBoundariesBcr(array $header, bool $english): array
    {
        $dateWord = $english ? $this->findWord($header, 'Date') : $this->findWord($header, 'operatiunii');
        $refWord = $english ? $this->findWordStarting($header, 'Oper.') : $this->findWord($header, 'Referinta', true);
        $debit = $this->findWord($header, 'Debit');
        $credit = $this->findWord($header, 'Credit');
        if (!$dateWord || !$refWord || !$debit || !$credit) {
            throw new PdfStatementNotRecognizedException('Antetul tabelului BCR este incomplet.');
        }
        $b3 = $english ? ($refWord->x1 + $debit->x0) / 2 : $debit->x0 - 20.0;
        $b4 = $english ? ($debit->x1 + $credit->x0) / 2 : $credit->x0 - 20.0;

        return [-INF, $dateWord->x1, $refWord->x0 - 4.0, $b3, $b4];
    }

    private function isOpeningCell(array $c, callable $money): bool
    {
        $low = array_map(fn ($x) => $this->normalise($x), $c);
        if (in_array('sold', $low, true) && in_array('initial', $low, true)) {
            return true;
        }

        return $money($c[4] ?? '') !== null && trim($c[0] ?? '') !== '' && trim($c[1] ?? '') !== '';
    }

    private function isTableHeaderCells(array $c): bool
    {
        if (count($c) < 5) {
            return false;
        }
        $l = array_map(fn ($x) => $this->normalise($x), $c);
        $hasDebit = in_array('debit', $l, true);
        $hasCredit = in_array('credit', $l, true);

        return (str_starts_with($l[0], 'data') && str_starts_with($l[1], 'explicatie') && $hasDebit && $hasCredit)
            || (str_starts_with($l[0], 'data') && str_starts_with($l[2], 'document'))
            || str_starts_with($l[0], 'tranzactii finalizate')
            || (str_starts_with($l[0], 'operation date') && str_starts_with($l[1], 'explanation') && $hasDebit && $hasCredit)
            || (str_starts_with($l[0], 'value date') && $l[2] === 'bill');
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $n) {
            if (str_contains($haystack, $n)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, PdfWord[]> $lines
     */
    private function extractHolder(array $lines, bool $english): ?string
    {
        $label = $english ? 'owner' : 'titular';
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            if (!str_contains($this->normalise($this->lineText($line)), $label)) {
                continue;
            }
            $tokens = [];
            $seen = false;
            foreach ($line as $w) {
                if (!$seen) {
                    if (str_starts_with(strtolower($w->text), $label)) {
                        $seen = true;
                    }
                    continue;
                }
                if ($this->startsWithAny($w->text, self::HOLDER_STOP)) {
                    break;
                }
                $tokens[] = $w;
            }
            $start = $i + 1;
            if ($tokens === [] && $i + 1 < $count) {
                foreach ($lines[$i + 1] as $w) {
                    if ($this->startsWithAny($w->text, self::HOLDER_STOP)) {
                        break;
                    }
                    $tokens[] = $w;
                }
                $start = $i + 2;
            }
            if ($tokens !== []) {
                $texts = array_map(fn (PdfWord $w) => $w->text, $tokens);
                $left = $tokens[0]->x0;
                for ($j = $start; $j < $count && $j - $start < 3; $j++) {
                    $l = $lines[$j];
                    if ($l === [] || abs($l[0]->x0 - $left) > 2.0) {
                        break;
                    }
                    $more = [];
                    foreach ($l as $w) {
                        if ($this->startsWithAny($w->text, self::HOLDER_STOP)) {
                            break;
                        }
                        $more[] = $w->text;
                    }
                    if ($more === []) {
                        break;
                    }
                    $texts = array_merge($texts, $more);
                }
                $name = trim(implode(' ', $texts));
                if ($name !== '') {
                    return $name;
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, PdfWord[]> $lines
     * @param PdfWord[] $words
     */
    private function extractFiscalCode(array $lines, array $words, bool $english): ?string
    {
        $rx = $english ? '/UIC\/PN:\s*(\d+)/i' : '/CUI\/CNP:\s*(\d+)/i';
        foreach ($lines as $line) {
            if (preg_match($rx, $this->lineText($line), $m)) {
                return $m[1];
            }
        }
        if (preg_match($rx, implode(' ', array_map(fn (PdfWord $w) => $w->text, $words)), $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @param array<int, PdfWord[]> $lines
     * @param PdfWord[] $words
     */
    private function extractCurrencyBcr(array $lines, array $words): string
    {
        foreach ($lines as $line) {
            $t = array_map(static fn (PdfWord $w) => trim($w->text), $line);
            for ($i = 0; $i < count($t) - 1; $i++) {
                if (strcasecmp($t[$i], 'valuta') !== 0 && strcasecmp($t[$i], 'currency') !== 0) {
                    continue;
                }
                for ($j = $i + 1; $j < count($t); $j++) {
                    if ($t[$j] === '') {
                        continue;
                    }
                    $c = $this->normCurrency($t[$j]);
                    if (in_array($c, self::ISO, true)) {
                        return $c;
                    }
                    break;
                }
            }
        }
        $counts = [];
        foreach ($words as $w) {
            $c = $this->normCurrency($w->text);
            if (in_array($c, self::ISO, true)) {
                $counts[$c] = ($counts[$c] ?? 0) + 1;
            }
        }
        if ($counts !== []) {
            arsort($counts);

            return (string) array_key_first($counts);
        }

        return 'RON';
    }

    private function normCurrency(string $token): string
    {
        $t = strtoupper(trim($token));

        return match ($t) {
            'LEI' => 'RON',
            'RON.' => 'RON',
            'EUR.' => 'EUR',
            default => $t,
        };
    }

    private function startsWithAny(string $text, array $prefixes): bool
    {
        $t = strtolower($text);
        foreach ($prefixes as $p) {
            if (str_starts_with($t, $p)) {
                return true;
            }
        }

        return false;
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
     * @param PdfWord[] $words
     */
    private function findWordStarting(array $words, string $prefix): ?PdfWord
    {
        foreach ($words as $w) {
            if (stripos($w->text, $prefix) === 0) {
                return $w;
            }
        }

        return null;
    }
}
