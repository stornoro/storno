<?php

namespace App\Service\Borderou\Pdf\Bank;

use App\Service\Borderou\Pdf\AbstractPdfStatementParser;
use App\Service\Borderou\Pdf\PdfStatement;
use App\Service\Borderou\Pdf\PdfStatementNotRecognizedException;
use App\Service\Borderou\Pdf\PdfStatementTransaction;
use App\Service\Borderou\Pdf\PdfWord;

/**
 * Revolut Bank UAB (Romanian branch) account statement: table
 * "Date (UTC) / Description / Money out / Money in / Balance", newest first,
 * dates "6 Mar 2025", amounts "€1,000.00", "ID: <uuid>" continuation lines.
 */
class RevolutPdfParser extends AbstractPdfStatementParser
{
    use NeoBankHelpersTrait;

    private const UUID_REF = '/\bID\s*:\s*([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\b/i';

    public function getBankKey(): string
    {
        return 'revolut';
    }

    public function getBankLabel(): string
    {
        return 'Revolut';
    }

    public function score(array $page1Words): int
    {
        $t = $this->normalise(implode(' ', $page1Words));
        $a = str_contains($t, 'revolut bank uab');
        $b = str_contains($t, $this->normalise('Vilnius Sucursala București'));
        $c = str_contains($t, 'account statement');
        if ($a && $b && $c) {
            return 100;
        }
        if (str_contains($t, $this->normalise('Konstitucijos ave. 21B, Vilnius, 08130, the Republic of Lithuania'))) {
            return 50;
        }

        return ($a || $b) ? 50 : 0;
    }

    public function parse(array $pages): array
    {
        $page1 = $pages[0];
        $words = $this->page1Words($page1);

        $iban = null;
        if (preg_match('/(?<![A-Z0-9])RO\d{2}(?:\s?[A-Z0-9]{4}){5}(?![A-Z0-9])/i', implode(' ', array_map(static fn (PdfWord $w) => $w->text, $words)), $m)) {
            $iban = strtoupper(preg_replace('/\s+/', '', $m[0]) ?? $m[0]);
        }
        $holder = $this->holder($page1);
        $currency = $this->currencyAfterWord($words, 'Valuta', 15, self::NEO_ISO_EXT)
            ?? $this->currencyAfterWord($words, 'Currency', 15, self::NEO_ISO_EXT)
            ?? $this->firstIsoCurrency($words, self::NEO_ISO_EXT)
            ?? 'RON';

        // --- table -------------------------------------------------------
        $lines = $this->linesInBand($pages, 75.0, 800.0);
        $headerIdx = null;
        foreach ($lines as $i => $line) {
            if ($this->isHeaderLine($line)) {
                $headerIdx = $i;
                break;
            }
        }
        if ($headerIdx === null || count($lines[$headerIdx]) !== 8) {
            throw new PdfStatementNotRecognizedException('Nu am putut identifica antetul tabelului (Data / Descriere / Debit / Credit) in PDF-ul Revolut. Este posibil ca formatul extrasului sa fi fost modificat sau ca banca sa nu fie inca suportata.');
        }
        $hw = $lines[$headerIdx];
        $wDate = $this->findWord($hw, static fn (string $t) => $t === 'date' || $t === 'reference');
        $wDesc = $this->findWord($hw, static fn (string $t) => str_starts_with($t, 'description'));
        $wMoney1 = $this->findWord($hw, static fn (string $t) => str_starts_with($t, 'money'));
        $wMoney2 = $this->findWord($hw, static fn (string $t) => str_starts_with($t, 'money'), true);
        if (!$wDate || !$wDesc || !$wMoney1 || !$wMoney2) {
            throw new PdfStatementNotRecognizedException('Antetul tabelului Revolut este incomplet.');
        }
        $bounds = [-INF, $wDate->x1 + 8.0, $wDesc->x0 - 16.0, $wMoney1->x0 - 32.0, $wMoney1->x1 + 16.0, $wMoney2->x1];

        // --- opening / closing balance ------------------------------------
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
            $cells = $this->splitByBoundaries($line, $bounds);
            $joined = trim(implode(' ', $cells));
            if (str_contains($this->normalise($this->lineText($line)), 'transaction types')) {
                break;
            }
            if ($this->isHeaderLine($line)) {
                continue; // repeated header on a following page
            }
            // Column 1 is the gap between the date and description columns; a long date
            // ("16 Mar 2025") can spill its year into it, so the two are read together.
            $date = $this->parseDateStrict(trim($cells[0] . ' ' . $cells[1])) ?? $this->parseDateStrict($cells[0]);
            $out = $this->moneyBySeparator($cells[3]);
            $in = $this->moneyBySeparator($cells[4]);
            $hasOut = $this->nonZero($out);
            $hasIn = $this->nonZero($in);
            if ($date !== null && ($hasOut xor $hasIn)) {
                $flush();
                $current = [
                    'date' => $date,
                    'credit' => $hasIn,
                    'amount' => $this->bcAbs($hasIn ? $in : $out),
                    'description' => trim($cells[2]),
                    'raw' => [$joined],
                ];
                continue;
            }
            if ($current !== null) {
                if (trim($cells[2]) !== '') {
                    $current['description'] .= ' ' . trim($cells[2]);
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
            [$description, $reference] = $this->extractReference($row['description']);
            $transactions[] = new PdfStatementTransaction(
                $row['date'],
                $description,
                $row['credit'] ? '0.00' : $row['amount'],
                $row['credit'] ? $row['amount'] : '0.00',
                $reference,
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
        $pair = false;
        for ($i = 0; $i + 1 < count($t); $i++) {
            if ($t[$i] === 'date' && $t[$i + 1] === '(utc)') {
                $pair = true;
                break;
            }
        }
        $hasDesc = false;
        foreach ($t as $tok) {
            if (str_starts_with($tok, 'description')) {
                $hasDesc = true;
            }
        }

        return $pair && $hasDesc && in_array('out', $t, true) && in_array('in', $t, true);
    }

    /**
     * @param PdfWord[] $words
     * @param callable(string): bool $match receives the normalised text
     */
    private function findWord(array $words, callable $match, bool $last = false): ?PdfWord
    {
        $found = null;
        foreach ($words as $w) {
            if ($match($this->normalise($w->text))) {
                $found = $w;
                if (!$last) {
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * Spec: the holder is the fixed box Left 40..320, Top 680..725 on page 1. The box also
     * catches address lines, so only its first line is kept.
     */
    private function holder(\App\Service\Borderou\Pdf\PdfPage $page1): ?string
    {
        $box = $this->boxWords($page1, 40.0, 320.0, 725.0, 680.0);
        if ($box === []) {
            return null;
        }
        $lines = $this->clusterer->cluster($box, 1.5);
        $name = trim($this->lineText($lines[0]));

        return $name === '' ? null : $name;
    }

    /**
     * Spec: "Opening balance" / "Closing balance" (or "Sold initial" / "Sold final") are looked
     * up in flat lines 12..19; when that window misses, every line above the table header is tried.
     *
     * @param array<int, PdfWord[]> $lines
     * @return array{0: ?string, 1: ?string}
     */
    private function balances(array $lines, int $headerIdx): array
    {
        $opening = null;
        $closing = null;
        $scan = function (array $indexes) use ($lines, &$opening, &$closing): void {
            foreach ($indexes as $i) {
                if (!isset($lines[$i])) {
                    continue;
                }
                $line = $lines[$i];
                $t = $this->normalise($this->lineText($line));
                if ($opening === null && ((str_contains($t, 'sold') && str_contains($t, 'initial')) || str_contains($t, 'opening balance') || str_contains($t, 'sold') || str_contains($t, 'opening'))) {
                    $v = $this->extractMoney($line);
                    if ($v !== null) {
                        $opening = $v;
                        continue;
                    }
                }
                if ($this->hasKnownToken($line, 'Sold final') || $this->hasKnownToken($line, 'Closing balance')) {
                    $v = $this->extractMoney($line);
                    if ($v !== null) {
                        $closing = $v;

                        return;
                    }
                }
            }
        };
        $scan(range(12, 19));
        if ($opening === null || $closing === null) {
            $before = $headerIdx > 0 ? range(0, $headerIdx - 1) : [];
            $scan($before);
        }

        return [$opening, $closing];
    }

    /**
     * Spec "TryExtractMoney": every token holding a digit/./, glued together, then parsed.
     *
     * @param PdfWord[] $line
     */
    private function extractMoney(array $line): ?string
    {
        $parts = [];
        foreach ($line as $w) {
            if (preg_match('/[\d.,]/', $w->text)) {
                $parts[] = $w->text;
            }
        }
        if ($parts === []) {
            return null;
        }

        return $this->moneyBySeparator(implode('', $parts));
    }

    /**
     * @return array{0: string, 1: ?string} cleaned description, UUID reference
     */
    private function extractReference(string $description): array
    {
        $reference = null;
        if (preg_match(self::UUID_REF, $description, $m)) {
            $reference = strtolower($m[1]);
            $description = preg_replace(self::UUID_REF, '', $description, 1) ?? $description;
        }
        $description = preg_replace('/[ \t]{2,}/', ' ', $description) ?? $description;
        $description = preg_replace('/^\s*$\r?\n?/m', '', $description) ?? $description;

        return [trim($description), $reference];
    }
}
