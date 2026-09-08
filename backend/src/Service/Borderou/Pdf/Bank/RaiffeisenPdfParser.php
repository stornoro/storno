<?php

namespace App\Service\Borderou\Pdf\Bank;

use App\Service\Borderou\Pdf\AbstractPdfStatementParser;
use App\Service\Borderou\Pdf\PdfStatement;
use App\Service\Borderou\Pdf\PdfStatementNotRecognizedException;
use App\Service\Borderou\Pdf\PdfWord;

/**
 * Raiffeisen Bank "Extras de cont": two date columns (Data inregistrare / Data tranzactiei),
 * Descrierea tranzactiei, Referinta, Debit, Credit, Sold; amounts 1,234.56, dates dd.MM.yyyy,
 * balances from the "Sold initial rulaj debitor rulaj creditor Sold final" summary row.
 */
class RaiffeisenPdfParser extends AbstractPdfStatementParser
{
    private const ISO = ['RON', 'EUR', 'USD', 'GBP', 'CHF', 'JPY', 'AUD', 'CAD', 'SEK', 'NOK', 'DKK'];
    private const REF_REGEX = '/\b95400000000000\d{6}\b/';

    public function getBankKey(): string
    {
        return 'raiffeisen';
    }

    public function getBankLabel(): string
    {
        return 'Raiffeisen Bank';
    }

    public function score(array $page1Words): int
    {
        $t = $this->normalise(implode(' ', $page1Words));
        if (str_contains($t, 'www.raiffeisen.ro')) {
            return 100;
        }
        $a = str_contains($t, 'raiffeisen bank s.a.');
        $b = str_contains($t, 'calea floreasca nr. 246 d');
        if ($a && $b) {
            return 100;
        }

        return ($a || $b) ? 50 : 0;
    }

    public function parse(array $pages): array
    {
        $page1 = $pages[0];
        $r = $this->wordsInBand($page1, 90.0);
        $this->sortReading($r);

        $iban = $this->cleanIban($this->afterLabel($r, 'Cod', 'IBAN'));
        $left = array_values(array_filter($r, static fn (PdfWord $w) => $w->x0 < 280.0));
        $holder = $this->holderUnderGenerareExtras($left) ?? $this->holderAfterBankLabel($left);
        $fiscalCode = $this->fiscalCode($left);
        $currency = $this->currencyAfterLabel($r, 'valuta:') ?? $this->firstIsoCurrency($page1->words, self::ISO) ?? 'RON';

        // --- table -------------------------------------------------------
        $lines = [];
        foreach ($pages as $page) {
            foreach ($this->clusterer->cluster($this->wordsInBand($page, 93.0), 2.5) as $line) {
                $lines[] = $line;
            }
        }

        [$opening, $printedClosing] = $this->summaryBalances($lines);

        $headerIdx = null;
        foreach ($lines as $i => $w) {
            if (count($w) >= 8 && strcasecmp($w[1]->text, 'Data') === 0 && stripos($w[2]->text, 'Descrierea') === 0
                && $this->hasWord($w, 'debit') && $this->hasWord($w, 'credit')) {
                $headerIdx = $i;
                break;
            }
        }
        if ($headerIdx === null || count($lines[$headerIdx]) !== 8) {
            throw new PdfStatementNotRecognizedException('Nu am putut identifica antetul tabelului (Data / Descriere / Debit / Credit) in PDF-ul Raiffeisen. Este posibil ca formatul extrasului sa fi fost modificat.');
        }
        $hw = $lines[$headerIdx];
        $dateWord = $this->firstWordMatching($hw, '/^Dat/i') ?? $hw[0];
        $descrWord = null;
        foreach ($hw as $w) {
            if (strcasecmp($w->text, 'Descrierea') === 0) {
                $descrWord = $w;
            }
        }
        $descrWord ??= $hw[2];
        $debitWord = $this->firstWordMatching($hw, '/^Debit$/i');
        $creditWord = $this->firstWordMatching($hw, '/^Credit$/i');
        if (!$debitWord || !$creditWord) {
            throw new PdfStatementNotRecognizedException('Antetul tabelului Raiffeisen este incomplet.');
        }
        $bounds = [-INF, $dateWord->x1 + 8.0, $descrWord->x0, $debitWord->x0 - 40.0, $creditWord->x0 - 40.0];

        $cellRows = [];
        $count = count($lines);
        for ($i = $headerIdx + 2; $i < $count; $i++) {
            $cellRows[] = $this->splitByBoundaries($lines[$i], $bounds);
        }

        $money = function (?string $v): ?string {
            $m = $this->parseMoneyEn($v);

            return ($m === null || bccomp($m, '0', 2) === 0) ? null : $m;
        };

        $result = $this->walkDebitCreditTable($cellRows, [
            'date' => 1,
            'desc' => 2,
            'debit' => 3,
            'credit' => 4,
            'money' => $money,
            'dateFormats' => ['d.m.Y'],
            'running' => $opening,
            'skip' => fn (array $c, string $joined): bool => $this->isTableHeader($c) || str_contains($this->normalise($joined), 'soldul zilei'),
            'isClosing' => fn (array $c): bool => str_contains($this->normalise(implode(' ', $c)), 'sold final'),
            'closingFrom' => fn (array $c): ?string => $printedClosing ?? $money($c[4] ?? '') ?? $money($c[3] ?? ''),
            'breakAtClosing' => true,
            'reference' => static function (string $desc): array {
                $ref = null;
                if (preg_match(self::REF_REGEX, $desc, $m)) {
                    $ref = $m[0];
                    $desc = preg_replace(self::REF_REGEX, '', $desc) ?? $desc;
                }

                return [trim(preg_replace('/\s{2,}/', ' ', $desc) ?? $desc), $ref];
            },
        ]);

        $warnings = $result['warnings'];
        $closing = $result['closing'] ?? $printedClosing;
        if ($mismatch = $this->closingMismatch($closing, $result['running'])) {
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
            $closing,
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
        if (str_starts_with($n[0], 'dat') && str_starts_with($n[1], 'data') && str_starts_with($n[2], 'descrierea')
            && $this->anyContains($n, 'debit') && $this->anyContains($n, 'credit')) {
            return true;
        }

        return str_starts_with($n[0], 'inregistrare') && str_starts_with($n[1], 'tranzac');
    }

    /**
     * @param string[] $cells normalised
     */
    private function anyContains(array $cells, string $needle): bool
    {
        foreach ($cells as $c) {
            if (str_contains($c, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "Sold initial rulaj debitor rulaj creditor Sold final" followed by the four amounts:
     * first amount = opening balance, last = closing balance.
     *
     * @param array<int, PdfWord[]> $lines
     * @return array{0: ?string, 1: ?string}
     */
    private function summaryBalances(array $lines): array
    {
        $count = count($lines);
        for ($k = 0; $k < $count - 1; $k++) {
            $w = $lines[$k];
            if (count($w) < 8) {
                continue;
            }
            if (strcasecmp($w[0]->text, 'Sold') === 0 && stripos($w[2]->text, 'rulaj') === 0
                && $this->hasWord($w, 'debitor') && $this->hasWord($w, 'creditor') && stripos($w[7]->text, 'final') === 0) {
                $next = $lines[$k + 1];
                $opening = $this->parseMoneyEn($next[0]->text);
                $closing = $this->parseMoneyEn($next[count($next) - 1]->text);

                return [$opening, $closing];
            }
        }

        return [null, null];
    }

    /**
     * Texts following "$first $second…" on a >= 3-word line, concatenated without separators.
     *
     * @param PdfWord[] $words
     */
    private function afterLabel(array $words, string $first, string $second): ?string
    {
        foreach ($this->clusterer->cluster($words, 1.5) as $line) {
            $n = count($line);
            if ($n < 3) {
                continue;
            }
            for ($i = 0; $i + 1 < $n; $i++) {
                if (strcasecmp($line[$i]->text, $first) === 0 && stripos($line[$i + 1]->text, $second) === 0) {
                    $rest = array_slice($line, $i + 2);
                    if ($rest === []) {
                        continue;
                    }

                    return implode('', array_map(static fn (PdfWord $w) => $w->text, $rest));
                }
            }
        }

        return null;
    }

    private function cleanIban(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $v = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '');

        return strlen($v) === 24 ? $v : null;
    }

    /**
     * Holder = first non-empty line under "Data generare extras", up to the "Cod" token.
     *
     * @param PdfWord[] $words
     */
    private function holderUnderGenerareExtras(array $words): ?string
    {
        $lines = $this->clusterer->cluster($words, 1.5);
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $t = $this->normalise($this->lineText($lines[$i]));
            if (!(str_contains($t, 'generare') && str_contains($t, 'extras') && preg_match('/\bdat/', $t))) {
                continue;
            }
            for ($j = $i + 1; $j < $count; $j++) {
                $tokens = [];
                $any = false;
                foreach ($lines[$j] as $w) {
                    $tok = trim($w->text, ':');
                    if (trim($w->text) !== '') {
                        $any = true;
                    }
                    if (strcasecmp($tok, 'Cod') === 0) {
                        break;
                    }
                    if ($tok !== '') {
                        $tokens[] = $tok;
                    }
                }
                if ($any) {
                    $name = trim(implode(' ', $tokens));

                    return $name === '' ? null : $name;
                }
            }

            return null;
        }

        return null;
    }

    /**
     * Fallback: tokens after "Bank:" up to and including "SRL".
     *
     * @param PdfWord[] $words
     */
    private function holderAfterBankLabel(array $words): ?string
    {
        $texts = array_map(static fn (PdfWord $w) => $w->text, $words);
        $start = null;
        foreach ($texts as $i => $t) {
            if (strcasecmp($t, 'Bank:') === 0) {
                $start = $i + 1;
                break;
            }
        }
        if ($start === null) {
            return null;
        }
        $parts = [];
        for ($i = $start; $i < count($texts); $i++) {
            if (strcasecmp($texts[$i], 'SRL') === 0) {
                break;
            }
            $parts[] = $texts[$i];
        }
        $parts[] = 'SRL';
        $name = trim(implode(' ', $parts));

        return $name === 'SRL' ? null : $name;
    }

    /**
     * "Cod unic: 12345678" (also tolerates "Cod unic de inregistrare: RO12345678").
     *
     * @param PdfWord[] $words
     */
    private function fiscalCode(array $words): ?string
    {
        $raw = $this->afterLabel($words, 'Cod', 'unic');
        if ($raw === null) {
            return null;
        }
        if (preg_match('/(?:RO)?(\d{2,10})/i', $raw, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Word following "Valuta:" in reading order (LEI => RON).
     *
     * @param PdfWord[] $words
     */
    private function currencyAfterLabel(array $words, string $label): ?string
    {
        $flat = [];
        foreach ($this->clusterer->cluster($words, 2.5) as $line) {
            foreach ($line as $w) {
                $flat[] = $w->text;
            }
        }
        $n = count($flat);
        for ($i = 0; $i < $n; $i++) {
            if (strcasecmp($flat[$i], $label) !== 0) {
                continue;
            }
            for ($j = $i + 1; $j < $n; $j++) {
                $t = rtrim(trim($flat[$j]), ':;.,');
                if ($t === '') {
                    continue;
                }

                return strcasecmp($t, 'LEI') === 0 ? 'RON' : strtoupper($t);
            }
        }

        return null;
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
