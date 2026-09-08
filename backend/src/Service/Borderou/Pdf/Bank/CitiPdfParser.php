<?php

namespace App\Service\Borderou\Pdf\Bank;

use App\Service\Borderou\Pdf\AbstractPdfStatementParser;
use App\Service\Borderou\Pdf\PdfPage;
use App\Service\Borderou\Pdf\PdfStatement;
use App\Service\Borderou\Pdf\PdfStatementNotRecognizedException;
use App\Service\Borderou\Pdf\PdfStatementTransaction;
use App\Service\Borderou\Pdf\PdfWord;

/**
 * Citibank Europe plc (Romania branch): table
 * "DATA / DATA VALUTEI / DESCRIERE / DEBIT / CREDIT / SOLD CURENT", amounts 1,234.56,
 * dates dd-MMM-yyyy (English months), balances in "Balanta de deschidere" / "Balanta de inchidere" rows,
 * descriptions made of "Label: value" segments that are reordered by relevance.
 */
class CitiPdfParser extends AbstractPdfStatementParser
{
    private const ISO = ['RON', 'EUR', 'USD', 'GBP', 'CHF', 'JPY', 'AUD', 'CAD', 'SEK', 'NOK', 'DKK'];
    private const HOLDER_STOP = ['numar', 'valuta', 'frecventa', 'perioada', 'data', 'balanta'];
    private const LABEL_RX = '/\b(Custom Reference|E2E Reference|Unique Reference Number|Cont beneficiar|Beneficiar|Din ordinul|Cont deschis la|Detalii de plata|Cont contrapartida|Contrapartida|Suma transferata|ORIGINATING AMOUNT|POST AMOUNT|REFERENCE NO|YOUR REFERENCE|OUR REFERENCE)\s*:/iu';
    private const PRIMARY_LABELS = ['detalii de plata', 'contrapartida', 'beneficiar', 'cont contrapartida'];
    private const SECONDARY_LABELS = ['cont beneficiar', 'suma transferata', 'originating amount', 'post amount', 'din ordinul', 'reference no', 'your reference', 'our reference'];

    public function getBankKey(): string
    {
        return 'citi';
    }

    public function getBankLabel(): string
    {
        return 'Citibank Europe';
    }

    public function score(array $page1Words): int
    {
        $t = $this->normalise(implode(' ', $page1Words));
        if (str_contains($t, 'citibank europe plc')) {
            return 100;
        }
        $a = str_contains($t, 'citibank');
        $b = str_contains($t, 'tiriac tower');
        if ($a && $b) {
            return 100;
        }

        return ($a || $b) ? 50 : 0;
    }

    public function parse(array $pages): array
    {
        $page1Lines = $this->excludeFooter($pages[0]);
        $firstPageWords = [];
        foreach ($page1Lines as $line) {
            foreach ($line as $w) {
                $firstPageWords[] = $w;
            }
        }
        usort($firstPageWords, static fn (PdfWord $a, PdfWord $b) => [$a->y0, $a->x0] <=> [$b->y0, $b->x0]);

        // --- metadata ----------------------------------------------------
        $iban = null;
        $joinedText = implode(' ', array_map(static fn (PdfWord $w) => $w->text, $firstPageWords));
        if (preg_match('/\b[A-Z]{2}\d{2}[A-Z0-9]{10,30}\b/', $joinedText, $m)) {
            $iban = strtoupper($m[0]);
        }
        $holder = $this->holderAfterTitularCont($firstPageWords);
        $currency = $this->currencyAfterValuta($firstPageWords) ?? $this->firstIsoCurrency($firstPageWords, self::ISO) ?? 'RON';

        // --- table -------------------------------------------------------
        $lines = [];
        foreach ($pages as $page) {
            foreach ($this->excludeFooter($page) as $line) {
                $lines[] = ['page' => $page->number, 'words' => $line];
            }
        }
        $headerIdx = null;
        foreach ($lines as $i => $entry) {
            if ($this->isHeaderLine($entry['words'])) {
                $headerIdx = $i;
                break;
            }
        }
        if ($headerIdx === null) {
            throw new PdfStatementNotRecognizedException('Nu am putut identifica antetul tabelului (Data / Data valutei / Descriere / Debit / Credit / Sold curent) in PDF-ul Citibank. Este posibil ca formatul extrasului sa fi fost modificat.');
        }
        $bounds = $this->boundaries($lines[$headerIdx]['words']);

        $transactions = [];
        $warnings = [];
        $current = null;
        $running = null;
        $opening = null;
        $printedClosing = null;
        $currentPage = null;
        $inTable = false;

        $flush = function () use (&$current, &$transactions): void {
            if ($current === null) {
                return;
            }
            $transactions[] = $this->buildTransaction($current);
            $current = null;
        };

        foreach ($lines as $entry) {
            if ($entry['page'] !== $currentPage) {
                $currentPage = $entry['page'];
                $inTable = false;
            }
            $cells = $this->splitByBoundaries($entry['words'], $bounds);
            $joined = trim(implode(' ', $cells));
            if ($this->isTableHeaderCells($cells)) {
                $inTable = true;
                continue;
            }
            if (!$inTable) {
                continue;
            }
            $desc = $this->normalise($cells[2]);
            if (str_contains($desc, 'balanta') && str_contains($desc, 'deschidere')) {
                $v = $this->parseMoneyEn($cells[5]);
                if ($v !== null) {
                    $running = $v;
                    $opening ??= $v;
                }
                continue;
            }
            if (str_contains($desc, 'balanta') && str_contains($desc, 'inchidere')) {
                $printedClosing = $this->parseMoneyEn($cells[5]);
                break;
            }
            $date = $this->parseDateStrict($cells[1]);
            if ($date !== null) {
                $flush();
                $current = [
                    'date' => $date,
                    'description' => trim($cells[2]),
                    'amount' => null,
                    'credit' => false,
                    'closing' => null,
                    'raw' => [$joined],
                ];
                $this->trySetAmount($current, $cells, $running, $warnings);
                continue;
            }
            if ($current !== null) {
                if (trim($cells[2]) !== '') {
                    $current['description'] .= ' ' . trim($cells[2]);
                }
                $current['raw'][] = $joined;
                $this->trySetAmount($current, $cells, $running, $warnings);
            }
        }
        $flush();

        if ($printedClosing !== null && bccomp($printedClosing, '0', 2) !== 0) {
            if ($mismatch = $this->closingMismatch($printedClosing, $running)) {
                $warnings[] = $mismatch;
            }
        }
        $warnings = array_merge($warnings, $this->validateAgainstPrintedTotals($lines, $transactions));
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
            array_values(array_unique($warnings)),
        )];
    }

    // ------------------------------------------------------------------
    // Rows
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $current
     * @param string[] $cells
     * @param string[] $warnings
     */
    private function trySetAmount(array &$current, array $cells, ?string &$running, array &$warnings): void
    {
        if ($current['amount'] !== null) {
            return;
        }
        $debit = $this->nonZeroMoney($cells[3]);
        $credit = $this->nonZeroMoney($cells[4]);
        if ($debit === null && $credit === null) {
            return;
        }
        $isCredit = $credit !== null;
        $amount = $this->bcAbs($isCredit ? $credit : $debit);
        $before = $running ?? '0.00';
        $closing = $isCredit ? bcadd($before, $amount, 2) : bcsub($before, $amount, 2);
        $printed = $this->parseMoneyEn($cells[5]);
        if ($running !== null && $printed !== null && bccomp($printed, $closing, 2) !== 0) {
            $warnings[] = sprintf('Soldul tiparit (%s) nu corespunde cu soldul calculat (%s) la tranzactia din %s.', $printed, $closing, $current['date']->format('d.m.Y'));
        }
        $current['amount'] = $amount;
        $current['credit'] = $isCredit;
        $current['closing'] = $closing;
        $running = $closing;
    }

    /**
     * @param array<string, mixed> $current
     */
    private function buildTransaction(array $current): PdfStatementTransaction
    {
        $raw = preg_replace('/\s{2,}/', ' ', trim($current['description'])) ?? '';
        $reference = null;
        if (preg_match('/Custom Reference\s*:\s*(\d+)/i', $raw, $m)) {
            $reference = $m[1];
        }
        [$description, $counterpartyName, $counterpartyIban] = $this->trimDescription($raw);
        $amount = $current['amount'] ?? '0.00';

        return new PdfStatementTransaction(
            $current['date'],
            $description,
            $current['credit'] ? '0.00' : $amount,
            $current['credit'] ? $amount : '0.00',
            $reference,
            null,
            $counterpartyName,
            $counterpartyIban,
            $current['closing'],
            null,
            $current['raw'],
        );
    }

    /**
     * Reorders the "Label: value" segments of a description by relevance.
     *
     * @return array{0: string, 1: ?string, 2: ?string} description, counterparty name, counterparty IBAN
     */
    private function trimDescription(string $description): array
    {
        if (!preg_match_all(self::LABEL_RX, $description, $matches, PREG_OFFSET_CAPTURE)) {
            return [str_replace('/', ' ', trim($description)), null, null];
        }
        $prefix = trim(substr($description, 0, $matches[0][0][1]));
        $segments = [];
        $count = count($matches[0]);
        for ($i = 0; $i < $count; $i++) {
            $start = $matches[0][$i][1];
            $end = $i + 1 < $count ? $matches[0][$i + 1][1] : strlen($description);
            $segments[] = [
                'label' => $this->normalise($matches[1][$i][0]),
                'text' => trim(substr($description, $start, $end - $start)),
                'value' => trim(substr($description, $start + strlen($matches[0][$i][0]), $end - $start - strlen($matches[0][$i][0]))),
            ];
        }

        $out = [];
        foreach (self::PRIMARY_LABELS as $label) {
            foreach ($segments as $s) {
                if ($s['label'] === $label) {
                    $out[] = $s['text'];
                }
            }
        }
        foreach ($segments as $s) {
            if (in_array($s['label'], self::SECONDARY_LABELS, true)) {
                $out[] = $s['text'];
            }
        }
        if ($prefix !== '') {
            $out[] = $prefix;
        }

        $name = null;
        foreach (['contrapartida', 'beneficiar'] as $label) {
            foreach ($segments as $s) {
                if ($s['label'] === $label && $s['value'] !== '') {
                    $name = $s['value'];
                    break 2;
                }
            }
        }
        $cpIban = null;
        foreach (['cont contrapartida', 'cont beneficiar'] as $label) {
            foreach ($segments as $s) {
                if ($s['label'] === $label) {
                    $cpIban = $this->extractIban($s['value']);
                    if ($cpIban !== null) {
                        break 2;
                    }
                }
            }
        }

        return [str_replace('/', ' ', implode(' ', $out)), $name, $cpIban];
    }

    /**
     * @param array<int, array{page: int, words: PdfWord[]}> $lines
     * @param PdfStatementTransaction[] $transactions
     * @return string[]
     */
    private function validateAgainstPrintedTotals(array $lines, array $transactions): array
    {
        $count = count($lines);
        for ($i = 0; $i < $count - 1; $i++) {
            $t = $this->normalise($this->lineText($lines[$i]['words']));
            if (!str_contains($t, 'numar total de debite') || !str_contains($t, 'numar total de credite')) {
                continue;
            }
            $next = $lines[$i + 1]['words'];
            if (count($next) !== 5) {
                return [];
            }
            if (!preg_match('/^\d+$/', $next[0]->text) || !preg_match('/^\d+$/', $next[1]->text)) {
                return [];
            }
            $totalDebit = $this->parseMoneyEn($next[3]->text);
            $totalCredit = $this->parseMoneyEn($next[4]->text);
            if ($totalDebit === null || $totalCredit === null) {
                return [];
            }
            $debits = 0;
            $credits = 0;
            $sumDebit = '0.00';
            $sumCredit = '0.00';
            foreach ($transactions as $tx) {
                if ($tx->isCredit()) {
                    $credits++;
                    $sumCredit = bcadd($sumCredit, $tx->credit, 2);
                } else {
                    $debits++;
                    $sumDebit = bcadd($sumDebit, $tx->debit, 2);
                }
            }
            $warnings = [];
            if ($debits !== (int) $next[0]->text || $credits !== (int) $next[1]->text) {
                $warnings[] = sprintf('Numarul de tranzactii citite (%d debite, %d credite) nu corespunde cu totalurile tiparite (%s debite, %s credite).', $debits, $credits, $next[0]->text, $next[1]->text);
            }
            if (bccomp($sumDebit, $totalDebit, 2) !== 0 || bccomp($sumCredit, $totalCredit, 2) !== 0) {
                $warnings[] = sprintf('Rulajele calculate (debit %s, credit %s) nu corespund cu cele tiparite in extras (debit %s, credit %s).', $sumDebit, $sumCredit, $totalDebit, $totalCredit);
            }

            return $warnings;
        }

        return [];
    }

    // ------------------------------------------------------------------
    // Layout
    // ------------------------------------------------------------------

    /**
     * Lines of a page, cut at the first footer line ("Citibank Europe plc" / "82-94 Buzesti").
     *
     * @return array<int, PdfWord[]>
     */
    private function excludeFooter(PdfPage $page): array
    {
        $out = [];
        foreach ($this->clusterer->cluster($page->words, 2.5) as $line) {
            $t = $this->normalise($this->lineText($line));
            if (str_contains($t, 'citibank europe plc') || str_starts_with($t, '82-94 buzesti')) {
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
        $data = 0;
        $desc = $debit = $credit = $sold = false;
        foreach ($line as $w) {
            $t = $this->normalise($w->text);
            if ($t === 'data') {
                $data++;
            } elseif (str_starts_with($t, 'descriere')) {
                $desc = true;
            } elseif ($t === 'debit') {
                $debit = true;
            } elseif ($t === 'credit') {
                $credit = true;
            } elseif ($t === 'sold') {
                $sold = true;
            }
        }

        return $data >= 2 && $desc && $debit && $credit && $sold;
    }

    /**
     * @param PdfWord[] $header
     * @return float[]
     */
    private function boundaries(array $header): array
    {
        $data = [];
        $desc = $debit = $credit = $sold = null;
        foreach ($header as $w) {
            $t = $this->normalise($w->text);
            if ($t === 'data') {
                $data[] = $w;
            } elseif ($desc === null && str_starts_with($t, 'descriere')) {
                $desc = $w;
            } elseif ($debit === null && $t === 'debit') {
                $debit = $w;
            } elseif ($credit === null && $t === 'credit') {
                $credit = $w;
            } elseif ($sold === null && $t === 'sold') {
                $sold = $w;
            }
        }
        usort($data, static fn (PdfWord $a, PdfWord $b) => $a->x0 <=> $b->x0);
        if (count($data) < 2 || !$desc || !$debit || !$credit || !$sold) {
            throw new PdfStatementNotRecognizedException('Nu am putut identifica coloanele tabelului in PDF-ul Citibank. Este posibil ca formatul extrasului sa fi fost modificat.');
        }

        return [
            -INF,
            $data[1]->x0 - 8.0,
            $desc->x0 - 8.0,
            $debit->x0 - 8.0,
            ($debit->x1 + $credit->x0) / 2,
            ($credit->x1 + $sold->x0) / 2,
        ];
    }

    /**
     * @param string[] $cells
     */
    private function isTableHeaderCells(array $cells): bool
    {
        if (count($cells) < 6 || stripos($cells[0], 'Data') !== 0) {
            return false;
        }
        $all = $this->normalise(implode(' ', $cells));

        return str_contains($all, 'descriere') && str_contains($all, 'debit') && str_contains($all, 'credit');
    }

    private function nonZeroMoney(string $cell): ?string
    {
        $v = $this->parseMoneyEn($cell);
        if ($v === null || bccomp($v, '0', 2) === 0) {
            return null;
        }

        return $v;
    }

    // ------------------------------------------------------------------
    // Metadata
    // ------------------------------------------------------------------

    /**
     * Words after "TITULAR CONT" up to the next metadata label.
     *
     * @param PdfWord[] $words
     */
    private function holderAfterTitularCont(array $words): ?string
    {
        foreach ($this->clusterer->cluster($words, 1.5) as $line) {
            $n = count($line);
            for ($i = 0; $i < $n - 1; $i++) {
                if (stripos($line[$i]->text, 'TITULAR') !== 0 || stripos($line[$i + 1]->text, 'CONT') !== 0) {
                    continue;
                }
                $parts = [];
                for ($j = $i + 2; $j < $n; $j++) {
                    $raw = $line[$j]->text;
                    if (trim($raw, ': ') === '') {
                        continue;
                    }
                    $t = $this->normalise($raw);
                    foreach (self::HOLDER_STOP as $stop) {
                        if (str_starts_with($t, $stop)) {
                            break 2;
                        }
                    }
                    $parts[] = $raw;
                }
                $value = trim(ltrim(trim(implode(' ', $parts)), ':'));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param PdfWord[] $words
     */
    private function currencyAfterValuta(array $words): ?string
    {
        $flat = [];
        foreach ($this->clusterer->cluster($words, 2.5) as $line) {
            foreach ($line as $w) {
                $flat[] = $w;
            }
        }
        $n = count($flat);
        for ($i = 0; $i < $n - 1; $i++) {
            if (!str_starts_with($this->normalise($flat[$i]->text), 'valut')) {
                continue;
            }
            for ($j = $i + 1; $j < $n; $j++) {
                $t = trim(ltrim(trim($flat[$j]->text), ':'));
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
