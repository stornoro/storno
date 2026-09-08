<?php

namespace App\Service\Borderou\Pdf\Bank;

use App\Service\Borderou\Pdf\AbstractPdfStatementParser;
use App\Service\Borderou\Pdf\PdfStatement;
use App\Service\Borderou\Pdf\PdfStatementNotRecognizedException;
use App\Service\Borderou\Pdf\PdfStatementTransaction;
use App\Service\Borderou\Pdf\PdfWord;

/**
 * Viva.com (VIVABANK S.A.) Romanian account statement: table
 * "Data tranzacției / Data valorii / Descriere / Valoare / Balanță" between the
 * "Soldul depus" and "Soldul reportat" rows; one signed amount column; the description
 * often starts on the line ABOVE the dated amount line. Some files print every glyph twice.
 * The statement's "iban" is the Viva account number (digits).
 */
class VivaPdfParser extends AbstractPdfStatementParser
{
    use NeoBankHelpersTrait;

    private const DESCRIPTION_STARTS = [
        'viva wallet', 'viva cashback', 'transfer wallet2wallet', 'transfer catre iban', 'comision transfer',
        'comision abonament', 'comision decontare', 'incasari carduri', 'taxa de facturare',
    ];
    private const LEGAL_FORMS = ['SRL', 'S.R.L.', 'S.R.L', 'SRL-D', 'SA', 'S.A.', 'PFA', 'P.F.A.', 'SNC', 'SCS', 'SCA'];

    public function getBankKey(): string
    {
        return 'viva';
    }

    public function getBankLabel(): string
    {
        return 'Viva.com (Viva Wallet)';
    }

    public function score(array $page1Words): int
    {
        $t = $this->normalise(implode(' ', $page1Words));
        $a = str_contains($t, 'vivabank');
        $b = str_contains($t, '005372901000');
        $c = str_contains($t, 'el999846755');
        if ($a && ($b || $c)) {
            return 100;
        }

        return ($a || $b || $c) ? 50 : 0;
    }

    public function parse(array $pages): array
    {
        $page1 = $pages[0];
        $words = $this->page1Words($page1);
        $lines15 = $this->clusterer->cluster($words, 1.5);

        $account = $this->accountNumber($lines15);
        $holder = $this->holder($lines15);
        $currency = $this->currency($lines15) ?? $this->firstIsoCurrency($words, self::NEO_ISO_EXT) ?? 'RON';

        // --- lines (60 < Top < 815, tolerance 5) tagged with their page ----------
        $lines = [];
        foreach ($pages as $page) {
            foreach ($this->clusterer->cluster($this->wordsInBand($page, 60.0, 815.0), 5.0) as $line) {
                $lines[] = ['page' => $page->number, 'words' => $line];
            }
        }

        // --- header: page-1 words above the "Soldul depus" row -----------------------
        $headerWords = [];
        foreach ($lines as $entry) {
            if ($entry['page'] !== $page1->number) {
                break;
            }
            if ($this->rowContains($entry['words'], 'soldul', 'depus')) {
                break;
            }
            foreach ($entry['words'] as $w) {
                $headerWords[] = $w;
            }
        }
        $wTranz = $this->findHeaderWord($headerWords, static fn (string $t) => str_starts_with($t, 'tranzac'));
        $wValorii = $this->findHeaderWord($headerWords, static fn (string $t) => $t === 'valorii');
        $wDescriere = $this->findHeaderWord($headerWords, static fn (string $t) => $t === 'descriere');
        $wValoare = $this->findHeaderWord($headerWords, static fn (string $t) => $t === 'valoare');
        $wBalan = $this->findHeaderWord($headerWords, static fn (string $t) => str_starts_with($t, 'balan'));
        if (!$wTranz || !$wValorii || !$wDescriere || !$wValoare || !$wBalan) {
            throw new PdfStatementNotRecognizedException('Nu am putut identifica antetul tabelului (Data tranzactiei / Data valorii / Descriere / Valoare / Balanta) in PDF-ul Viva. Este posibil ca formatul extrasului sa fi fost modificat sau ca banca sa nu fie inca suportata.');
        }
        $bounds = [-INF, ($wTranz->x1 + $wValorii->x0) / 2, $wValorii->x1 + 15.0, $wValoare->x0 - 10.0, ($wValoare->x1 + $wBalan->x0) / 2];

        // --- rows --------------------------------------------------------------------
        $transactions = [];
        $warnings = [];
        $current = null;
        $running = null;
        $opening = null;
        $allRowsHadBalance = true;
        $printedFinal = null;
        $finalCount = -1;
        $started = false;
        $flush = function () use (&$current, &$transactions, $account, $currency): void {
            if ($current === null) {
                return;
            }
            if (isset($current['date'])) {
                $description = trim(preg_replace('/\s{2,}/', ' ', $this->stripDiacritics($current['description'])) ?? '');
                $transactions[] = new PdfStatementTransaction(
                    $current['date'],
                    $description,
                    $current['credit'] ? '0.00' : $current['amount'],
                    $current['credit'] ? $current['amount'] : '0.00',
                    null,
                    $current['valueDate'] ?? null,
                    $this->counterpartyFromDescription($description),
                    null,
                    $current['closing'],
                    $currency,
                    $current['raw'],
                );
            }
            $current = null;
        };

        foreach ($lines as $entry) {
            $line = $entry['words'];
            if (!$started) {
                if ($this->rowContains($line, 'soldul', 'depus')) {
                    $started = true;
                    $running = $this->trailingAmount($line);
                    $opening = $running;
                }
                continue;
            }
            if ($this->rowContains($line, 'soldul', 'reportat')) {
                $flush();
                $v = $this->trailingAmount($line);
                if ($v !== null && $running !== null && bccomp($v, $running, 2) !== 0) {
                    $warnings[] = sprintf('Soldul reportat (%s) nu corespunde cu soldul calculat (%s).', $v, $running);
                }
                $printedFinal = $v;
                $finalCount = count($transactions);
                break;
            }
            $cells = $this->splitByBoundaries($line, $bounds);
            $joined = trim(implode(' ', $cells));
            $date = $this->parseDateStrict(trim($cells[0]));
            $value = $this->money($cells[3]);
            $desc = trim($cells[2]);

            if ($date !== null && $value !== null) {
                if ($current !== null && isset($current['date'])) {
                    $flush();
                }
                if ($current === null) {
                    $current = ['description' => '', 'raw' => []];
                }
                $credit = bccomp($value, '0', 2) >= 0;
                $amount = $this->bcAbs($value);
                $open = $running ?? '0.00';
                $closing = $credit ? bcadd($open, $amount, 2) : bcsub($open, $amount, 2);
                $balance = $this->money($cells[4]);
                if ($balance !== null) {
                    if (bccomp($balance, $closing, 2) !== 0) {
                        $warnings[] = sprintf('Balanta tiparita (%s) nu corespunde cu soldul calculat (%s) la randul: %s', $balance, $closing, $joined);
                    }
                    $printedFinal = $balance;
                    $finalCount = count($transactions) + 1;
                } else {
                    $allRowsHadBalance = false;
                }
                $running = $closing;
                $current['date'] = $date;
                $current['valueDate'] = $this->parseDateStrict(trim($cells[1]));
                $current['credit'] = $credit;
                $current['amount'] = $amount;
                $current['closing'] = $closing;
                if ($desc !== '') {
                    $current['description'] = $current['description'] === '' ? $desc : $current['description'] . ' ' . $desc;
                }
                $current['raw'][] = $joined;
                continue;
            }
            if ($this->isDescriptionStart($desc)) {
                $flush();
                $current = ['description' => $desc, 'raw' => [$joined]];
                continue;
            }
            if ($current !== null && $desc !== '') {
                $current['description'] .= ' ' . $desc;
                $current['raw'][] = $joined;
            }
        }
        $flush();

        if (!$started) {
            $warnings[] = 'Randul "Soldul depus" nu a fost gasit in extras.';
        }
        if ($transactions !== [] && !($finalCount === count($transactions) && $printedFinal !== null && $running !== null && bccomp($printedFinal, $running, 2) === 0 && $allRowsHadBalance)) {
            $warnings[] = $this->closingMismatch($printedFinal, $running)
                ?? 'Nu toate randurile au o balanta tiparita; verificati tranzactiile importate.';
        }
        if ($transactions === []) {
            $warnings[] = 'Nu a fost gasita nicio tranzactie in extras.';
        }

        return [new PdfStatement(
            $this->getBankKey(),
            $this->getBankLabel(),
            $account,
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

    /**
     * "SSoollddull" => "Soldul": collapses glyph pairs when every pair is doubled.
     */
    private function undouble(string $text): string
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $n = count($chars);
        if ($n < 4 || $n % 2 !== 0) {
            return $text;
        }
        $out = '';
        for ($i = 0; $i < $n; $i += 2) {
            if ($chars[$i] !== $chars[$i + 1]) {
                return $text;
            }
            $out .= $chars[$i];
        }

        return $out;
    }

    private function pdfText(string $text): string
    {
        return trim(str_replace(["\u{FB01}", "\u{FB02}", "\u{00A0}", "\u{200B}"], ['fi', 'fl', ' ', ''], $text));
    }

    /**
     * Money with the last-separator rule, restricted to amount-looking tokens so a date never parses.
     */
    private function money(string $text): ?string
    {
        $t = str_replace(["\u{2212}", "\u{2012}", "\u{2013}", "\u{2014}"], '-', trim($text));
        if (!$this->looksLikeAmount($t)) {
            return null;
        }

        return $this->moneyBySeparator($t);
    }

    /**
     * Rightmost word (undoubled) that parses as money.
     *
     * @param PdfWord[] $line
     */
    private function trailingAmount(array $line): ?string
    {
        for ($i = count($line) - 1; $i >= 0; $i--) {
            $v = $this->money($this->undouble($line[$i]->text));
            if ($v !== null) {
                return $v;
            }
        }

        return null;
    }

    /**
     * @param PdfWord[] $line
     */
    private function rowContains(array $line, string ...$needles): bool
    {
        $t = $this->normalise(implode(' ', array_map(fn (PdfWord $w) => $this->undouble($this->pdfText($w->text)), $line)));
        foreach ($needles as $needle) {
            if (!str_contains($t, $needle)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param PdfWord[] $words
     * @param callable(string): bool $match receives the undoubled, normalised text
     */
    private function findHeaderWord(array $words, callable $match): ?PdfWord
    {
        foreach ($words as $w) {
            if ($match($this->normalise($this->undouble($this->pdfText($w->text))))) {
                return $w;
            }
        }

        return null;
    }

    private function isDescriptionStart(string $desc): bool
    {
        $t = $this->normalise($this->pdfText($desc));
        foreach (self::DESCRIPTION_STARTS as $prefix) {
            if (str_starts_with($t, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "Numar cont: 1234567890": first all-digit token (6+ digits) on the line.
     *
     * @param array<int, PdfWord[]> $lines
     */
    private function accountNumber(array $lines): ?string
    {
        foreach ($lines as $line) {
            $t = $this->normalise($this->pdfText($this->lineText($line)));
            if (!str_contains($t, 'numar') || !str_contains($t, 'cont')) {
                continue;
            }
            foreach ($line as $w) {
                $tok = trim($w->text, ': ');
                if (preg_match('/^\d{6,}$/', $tok)) {
                    return $tok;
                }
            }
        }

        return null;
    }

    /**
     * The client name sits to the right of "VIVABANK S.A." on the same row: keep the words
     * after the widest horizontal gap, up to and including the legal form (SRL, SA, PFA...).
     *
     * @param array<int, PdfWord[]> $lines
     */
    private function holder(array $lines): ?string
    {
        foreach ($lines as $line) {
            $hit = false;
            foreach ($line as $w) {
                if (stripos($w->text, 'VIVABANK') !== false) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                continue;
            }
            usort($line, static fn (PdfWord $a, PdfWord $b) => $a->x0 <=> $b->x0);
            $widest = -1.0;
            $splitAt = 0;
            for ($i = 1; $i < count($line); $i++) {
                $gap = $line[$i]->x0 - $line[$i - 1]->x1;
                if ($gap > $widest) {
                    $widest = $gap;
                    $splitAt = $i;
                }
            }
            $parts = [];
            foreach (array_slice($line, $splitAt) as $w) {
                $text = trim($w->text);
                if ($text === '') {
                    continue;
                }
                $parts[] = $text;
                if (in_array(strtoupper($text), self::LEGAL_FORMS, true)) {
                    break;
                }
            }
            $name = trim(implode(' ', $parts));

            return $name === '' ? null : $name;
        }

        return null;
    }

    /**
     * "Valuta: RON" (LEI => RON).
     *
     * @param array<int, PdfWord[]> $lines
     */
    private function currency(array $lines): ?string
    {
        foreach ($lines as $line) {
            $hit = false;
            foreach ($line as $w) {
                if (stripos($w->text, 'Valuta') === 0) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                continue;
            }
            foreach ($line as $w) {
                $iso = $this->isoToken($w->text, self::NEO_ISO_EXT);
                if ($iso !== null) {
                    return $iso;
                }
            }
        }

        return null;
    }
}
