<?php

namespace App\Service\Borderou\Pdf\Bank;

use App\Service\Borderou\Pdf\AbstractPdfStatementParser;
use App\Service\Borderou\Pdf\PdfPage;
use App\Service\Borderou\Pdf\PdfStatement;
use App\Service\Borderou\Pdf\PdfStatementNotRecognizedException;
use App\Service\Borderou\Pdf\PdfStatementTransaction;
use App\Service\Borderou\Pdf\PdfWord;

/**
 * Nexent Bank N.V. Amsterdam (Bucharest branch) Romanian statement: table
 * "Data operarii / Data valutei / Referinta / Explicatii / Suma DB / Suma CR / Sold" in the
 * lower part of each page, oldest first, English-style amounts, dates dd.MM.yyyy
 * (rows without a date inherit the previous one).
 */
class NexentPdfParser extends AbstractPdfStatementParser
{
    use NeoBankHelpersTrait;

    public function getBankKey(): string
    {
        return 'nexent';
    }

    public function getBankLabel(): string
    {
        return 'Nexent Bank';
    }

    public function score(array $page1Words): int
    {
        $t = $this->normalise(implode(' ', $page1Words));
        if (str_contains($t, 'www.nexentbank.ro')) {
            return 100;
        }
        $a = str_contains($t, 'nexent bank n.v. amsterdam');
        $b = str_contains($t, $this->normalise('Bd. Timișoara nr. 26Z'));
        if ($a && $b) {
            return 100;
        }

        return ($a || $b) ? 50 : 0;
    }

    public function parse(array $pages): array
    {
        $page1 = $pages[0];
        $words = $this->page1Words($page1);

        $iban = $this->firstWordMatching($words, '/\b[A-Z]{2}\d{2}[A-Z0-9]{10,30}\b/')?->text;
        $box = $this->boxWords($page1, 40.0, 510.0, 380.0, 300.0);
        $boxLines = $this->clusterer->cluster($box, 1.5);
        $holder = $this->holder($boxLines);
        $currency = $this->currency($boxLines) ?? $this->firstIsoCurrency($box, self::NEO_ISO) ?? 'RON';

        // --- table (50 < Top < 520) --------------------------------------
        $lines = $this->linesInBand($pages, 50.0, 520.0);
        $headerIdx = null;
        foreach ($lines as $i => $line) {
            if ($this->isHeaderLine($line)) {
                $headerIdx = $i;
                break;
            }
        }
        if ($headerIdx === null || count($lines[$headerIdx]) !== 11) {
            throw new PdfStatementNotRecognizedException('Nu am putut identifica antetul tabelului (Data / Referinta / Explicatii / Suma DB / Suma CR / Sold) in PDF-ul Nexent Bank. Este posibil ca formatul extrasului sa fi fost modificat sau ca banca sa nu fie inca suportata.');
        }
        $hw = $lines[$headerIdx];
        $wOper = $this->findWord($hw, ['operarii']);
        $wRef = $this->findWord($hw, ['referinta']);
        $wExpl = $this->findWord($hw, ['explicatii']);
        $wSuma = $this->findWord($hw, ['suma']);
        $wCR = $this->findWord($hw, ['cr'], true);
        $wSold = $this->findWord($hw, ['sold'], true);
        if (!$wOper || !$wRef || !$wExpl || !$wSuma || !$wCR || !$wSold) {
            throw new PdfStatementNotRecognizedException('Antetul tabelului Nexent Bank este incomplet.');
        }
        $bounds = [-INF, $wOper->x1 + 16.0, $wRef->x0 - 16.0, $wExpl->x0 - 8.0, $wSuma->x0 - 16.0, $wCR->x0 - 16.0, $wSold->x0 - 12.8];

        [$opening, $printedClosing] = $this->balances($lines, $headerIdx);

        // --- rows (oldest first), starting two lines after the header ----------
        $transactions = [];
        $warnings = [];
        $current = null;
        $running = $opening ?? '0.00';
        $date = null;
        $finalOnlyBalance = null;
        $flush = function () use (&$current, &$transactions, $currency): void {
            if ($current === null) {
                return;
            }
            $description = trim(preg_replace('/\s{2,}/', ' ', $current['description']) ?? '');
            $reference = trim($current['reference']);
            if ($reference !== '' && preg_match('/(?<=\/)\d+/', $reference, $m)) {
                $reference = $m[0];
            }
            $transactions[] = new PdfStatementTransaction(
                $current['date'],
                $description,
                $current['credit'] ? '0.00' : $current['amount'],
                $current['credit'] ? $current['amount'] : '0.00',
                $reference === '' ? null : $reference,
                $current['valueDate'],
                $this->counterpartyFromDescription($description),
                null,
                $current['closing'],
                $currency,
                $current['raw'],
            );
            $current = null;
        };
        $count = count($lines);
        for ($i = $headerIdx + 2; $i < $count; $i++) {
            $line = $lines[$i];
            if ($this->isHeaderLine($line)) {
                $i++; // repeated header on a following page: skip it and its units line
                continue;
            }
            $cells = $this->splitByBoundaries($line, $bounds);
            $joined = trim(implode(' ', $cells));
            if (trim(implode('', array_slice($cells, 0, 6))) === '' && trim($cells[6]) !== '') {
                $finalOnlyBalance = $this->parseMoneyEn($cells[6]);
                break; // balance-only line closes the table
            }
            $db = $this->parseMoneyEn($cells[4]);
            $cr = $this->parseMoneyEn($cells[5]);
            $hasDb = $this->nonZero($db);
            $hasCr = $this->nonZero($cr);
            if ($hasDb xor $hasCr) {
                $flush();
                $parsed = $this->parseDateStrict($cells[0]);
                if ($parsed !== null) {
                    $date = $parsed;
                }
                if ($date === null) {
                    $warnings[] = 'Rand cu suma fara data: ' . $joined;
                    continue;
                }
                $amount = $this->bcAbs($hasCr ? $cr : $db);
                $running = $hasCr ? bcadd($running, $amount, 2) : bcsub($running, $amount, 2);
                $current = [
                    'date' => $date,
                    'valueDate' => $this->parseDateStrict($cells[1]),
                    'credit' => $hasCr,
                    'amount' => $amount,
                    'description' => trim($cells[3]),
                    'reference' => trim($cells[2]),
                    'closing' => $running,
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

        if ($opening === null) {
            $warnings[] = 'Soldul initial nu a fost gasit in extras; soldurile tranzactiilor pornesc de la 0.';
        }
        $printedClosing ??= $finalOnlyBalance;
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
        if (count($line) < 11) {
            return false;
        }
        $t = $this->normTokens($line);

        return str_starts_with($t[0], 'data') && str_starts_with($t[2], 'data') && str_starts_with($t[4], 'referinta')
            && in_array('db', $t, true) && in_array('cr', $t, true);
    }

    /**
     * Spec: "Sold initial" / "Sold final" are looked up in flat lines 8..13; when that window
     * misses, the lines above the header (opening) and below it (closing) are tried with strict labels.
     *
     * @param array<int, PdfWord[]> $lines
     * @return array{0: ?string, 1: ?string}
     */
    private function balances(array $lines, int $headerIdx): array
    {
        $money = fn (string $v) => $this->parseMoneyEn($v);
        $opening = null;
        $closing = null;
        foreach (range(8, 13) as $i) {
            if (!isset($lines[$i])) {
                continue;
            }
            $line = $lines[$i];
            $t = $this->normalise($this->lineText($line));
            if ($opening === null) {
                $isOpening = (str_contains($t, 'sold') && str_contains($t, 'initial')) || str_contains($t, 'opening balance')
                    || str_contains($t, 'sold') || str_contains($t, 'initial');
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
        if ($opening === null) {
            for ($i = 0; $i < $headerIdx; $i++) {
                $t = $this->normalise($this->lineText($lines[$i]));
                if ((str_contains($t, 'sold') && str_contains($t, 'initial')) || str_contains($t, 'opening balance')) {
                    $opening = $this->rightmostMoney($lines[$i], $money);
                    if ($opening !== null) {
                        break;
                    }
                }
            }
        }
        if ($closing === null) {
            for ($i = $headerIdx + 1; $i < count($lines); $i++) {
                $closing = $this->closingOf($lines[$i], $money, false);
                if ($closing !== null) {
                    break;
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
        if ($this->hasKnownToken($line, 'Sold final') || $this->hasKnownToken($line, 'End balance')) {
            return $this->rightmostMoney($line, $money);
        }
        if (!$loose) {
            $t = $this->normalise($this->lineText($line));

            return (str_contains($t, 'sold final') || str_contains($t, 'closing balance')) ? $this->rightmostMoney($line, $money) : null;
        }
        if (count($line) > 4 && $money($line[4]->text) !== null && trim($line[0]->text) !== '' && trim($line[1]->text) !== '') {
            return $money($line[4]->text);
        }
        $t = $this->normalise($this->lineText($line));
        if (str_contains($t, 'sold') || str_contains($t, 'final') || str_contains($t, 'closing')) {
            return $this->rightmostMoney($line, $money);
        }

        return null;
    }

    /**
     * "Nume client: EXEMPLU SRL" inside the lower-middle page-1 box; the value stops at the
     * next label ("Moneda:") or IBAN so the rest of the line is not swallowed.
     *
     * @param array<int, PdfWord[]> $lines
     */
    private function holder(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (count($line) < 3) {
                continue;
            }
            for ($i = 0; $i + 1 < count($line); $i++) {
                if (stripos($this->pdfText($line[$i]->text), 'nume') === 0 && stripos($this->pdfText($line[$i + 1]->text), 'client') === 0) {
                    $name = $this->valueWords(array_slice($line, $i + 2));
                    if ($name !== '') {
                        return $name;
                    }
                }
            }
        }
        foreach ($lines as $line) {
            for ($i = 0; $i + 1 < count($line); $i++) {
                if (strcasecmp($this->pdfText($line[$i]->text), 'nume') === 0 && strcasecmp(trim($this->pdfText($line[$i + 1]->text), ':'), 'client') === 0) {
                    $parts = [];
                    $hasSrl = false;
                    foreach (array_slice($line, $i + 2) as $w) {
                        $t = trim($this->pdfText($w->text), ': ');
                        if (strcasecmp($t, 'SRL') === 0) {
                            $hasSrl = true;
                            break;
                        }
                        if ($t !== '') {
                            $parts[] = $t;
                        }
                    }
                    if ($hasSrl) {
                        $parts[] = 'SRL';
                    }
                    $name = trim(implode(' ', $parts));
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
     * "Moneda: RON": ISO code after the label on the same line, else on the next line.
     *
     * @param array<int, PdfWord[]> $lines
     */
    private function currency(array $lines): ?string
    {
        foreach ($lines as $i => $line) {
            foreach ($line as $k => $w) {
                if (strcasecmp(trim($this->pdfText($w->text), ':'), 'moneda') !== 0) {
                    continue;
                }
                foreach (array_slice($line, $k + 1) as $after) {
                    $iso = $this->isoToken($this->pdfText($after->text), self::NEO_ISO);
                    if ($iso !== null) {
                        return $iso;
                    }
                }
                foreach ($lines[$i + 1] ?? [] as $after) {
                    $iso = $this->isoToken($this->pdfText($after->text), self::NEO_ISO);
                    if ($iso !== null) {
                        return $iso;
                    }
                }
            }
        }

        return null;
    }

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
            if (in_array($this->normalise($w->text), $alternatives, true)) {
                $found = $w;
                if (!$last) {
                    break;
                }
            }
        }

        return $found;
    }
}
