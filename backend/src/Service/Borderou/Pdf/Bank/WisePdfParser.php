<?php

namespace App\Service\Borderou\Pdf\Bank;

use App\Service\Borderou\Pdf\AbstractPdfStatementParser;
use App\Service\Borderou\Pdf\PdfStatement;
use App\Service\Borderou\Pdf\PdfStatementNotRecognizedException;
use App\Service\Borderou\Pdf\PdfStatementTransaction;
use App\Service\Borderou\Pdf\PdfWord;

/**
 * Wise Europe SA Romanian "Extras de cont": table "Descriere / Bani primiți / Bani trimiși / Sold",
 * newest first; each transaction = description line(s) carrying the amounts, followed by a
 * "<d> <luna> <yyyy>  Tranzacție: <id>  Referință: <text>" line.
 */
class WisePdfParser extends AbstractPdfStatementParser
{
    use NeoBankHelpersTrait;

    private const DATE_LINE = '/^(?<date>\d{1,2}\s+[A-Za-zăâîșțĂÂÎȘȚşţŞŢ]+\s+\d{4})\s*(?<rest>.*)$/u';

    public function getBankKey(): string
    {
        return 'wise';
    }

    public function getBankLabel(): string
    {
        return 'Wise';
    }

    public function score(array $page1Words): int
    {
        $t = $this->normalise(implode(' ', $page1Words));
        $a = str_contains($t, 'wise europe sa');
        $b = str_contains($t, $this->normalise('Rue du Trône'));
        if ($a && $b) {
            return 100;
        }

        return ($a || $b) ? 50 : 0;
    }

    public function parse(array $pages): array
    {
        $page1 = $pages[0];
        $words = $this->page1Words($page1);
        $lines15 = $this->clusterer->cluster($words, 1.5);

        $iban = null;
        foreach ($lines15 as $line) {
            if (preg_match('/\b[A-Z]{2}\d{2}(?:[ \t]?[A-Z0-9]{4}){2,7}\b/', $this->lineText($line), $m)) {
                $iban = strtoupper(preg_replace('/\s+/', '', $m[0]) ?? $m[0]);
                break;
            }
        }
        $holder = $this->holder($lines15);
        $currency = $this->currency($words);

        // --- table -------------------------------------------------------
        $lines = array_map(static fn (array $l) => $l['words'], $this->allLines($pages));
        $headerIdx = null;
        foreach ($lines as $i => $line) {
            if ($this->isHeaderLine($line)) {
                $headerIdx = $i;
                break;
            }
        }
        if ($headerIdx === null) {
            throw new PdfStatementNotRecognizedException('Nu am putut identifica antetul tabelului (Descriere / Bani primiti / Bani trimisi / Sold) in PDF-ul Wise. Este posibil ca formatul extrasului sa fi fost modificat sau ca banca sa nu fie inca suportata.');
        }
        $hw = $lines[$headerIdx];
        $bani = array_values(array_filter($hw, fn (PdfWord $w) => $this->normalise($w->text) === 'bani'));
        usort($bani, static fn (PdfWord $a, PdfWord $b) => $a->x0 <=> $b->x0);
        $wPrimi = $this->findWord($hw, static fn (string $t) => str_starts_with($t, 'primi'));
        $wTrimi = $this->findWord($hw, static fn (string $t) => str_starts_with($t, 'trimi'));
        $wSold = $this->findWord($hw, static fn (string $t) => $t === 'sold');
        if (count($bani) < 2 || !$wPrimi || !$wTrimi || !$wSold) {
            throw new PdfStatementNotRecognizedException('Antetul tabelului Wise este incomplet.');
        }
        $bounds = [-INF, $bani[0]->x0 - 90.0, ($wPrimi->x1 + $bani[1]->x0) / 2, ($wTrimi->x1 + $wSold->x0) / 2];
        $rightEdges = [$wPrimi->x1, $wTrimi->x1, $wSold->x1];

        $printedFinal = $this->printedFinalBalance($lines, $currency);

        // --- rows (newest first) ------------------------------------------
        $rows = [];
        $warnings = [];
        $current = null;
        $prevOpening = null;
        $count = count($lines);
        for ($i = $headerIdx + 1; $i < $count; $i++) {
            $line = $lines[$i];
            if (stripos($line[0]->text, 'ref:') === 0) {
                continue;
            }
            if ($this->isHeaderLine($line)) {
                continue; // repeated header on a following page
            }
            $joined = trim($this->lineText($line));
            if (preg_match(self::DATE_LINE, $joined, $m)) {
                if ($current === null) {
                    continue;
                }
                $date = $this->parseDateStrict($m['date']);
                if ($date === null) {
                    $warnings[] = 'Data tranzactiei nu a putut fi citita: ' . $m['date'];
                    $current = null;
                    continue;
                }
                $rest = trim($m['rest']);
                $ref = null;
                $id = null;
                if (preg_match('/Referin[țţt][ăa]\s*:\s*(?<ref>.+)$/iu', $rest, $r, PREG_OFFSET_CAPTURE)) {
                    $ref = trim($r['ref'][0]);
                    $rest = trim(substr($rest, 0, $r[0][1]));
                }
                if (preg_match('/Tranzac[țţt]ie\s*:\s*(?<id>[A-Za-z0-9\-_]+)/iu', $rest, $r, PREG_OFFSET_CAPTURE)) {
                    $id = $r['id'][0];
                    $rest = trim(substr($rest, 0, $r[0][1]));
                }
                if ($rest !== '') {
                    $current['description'] .= ' ' . $rest;
                }
                if ($ref !== null && $ref !== '' && preg_match('/cu referin[țţt][ăa]\s+.*$/iu', $current['description'])) {
                    $current['description'] = preg_replace('/cu referin[țţt][ăa]\s+.*$/iu', 'cu referinta ' . $ref, $current['description']) ?? $current['description'];
                }
                $current['date'] = $date;
                $current['reference'] = $id;
                $current['raw'][] = $joined;
                $rows[] = $current;
                $prevOpening = $current['opening'];
                $current = null;
                continue;
            }

            $cells = $this->splitByBoundaries($line, $bounds);
            $received = $this->amountAt($line, $rightEdges[0]);
            $sent = $this->amountAt($line, $rightEdges[1]);
            $balance = $this->amountAt($line, $rightEdges[2]);
            $hasReceived = $this->nonZero($received);
            $hasSent = $this->nonZero($sent);
            if ($current === null) {
                if (($hasReceived xor $hasSent) && $balance !== null) {
                    $amount = $this->bcAbs($hasReceived ? $received : $sent);
                    $expected = $prevOpening ?? $balance;
                    if (bccomp($balance, $expected, 2) !== 0) {
                        $warnings[] = sprintf('Soldul tiparit (%s) nu corespunde cu soldul calculat (%s) la randul: %s', $balance, $expected, $joined);
                    }
                    $current = [
                        'credit' => $hasReceived,
                        'amount' => $amount,
                        'closing' => $balance,
                        'opening' => $hasReceived ? bcsub($balance, $amount, 2) : bcadd($balance, $amount, 2),
                        'description' => trim($cells[0]),
                        'raw' => [$joined],
                    ];
                }
                continue;
            }
            if (trim($cells[0]) !== '') {
                $current['description'] = trim($current['description'] . ' ' . trim($cells[0]));
            }
            $current['raw'][] = $joined;
        }
        if ($current !== null) {
            $warnings[] = 'Tranzactie fara linie de data, ignorata: ' . ($current['raw'][0] ?? '');
        }

        // --- post-processing: oldest first -------------------------------------
        $rows = array_reverse($rows);
        $transactions = [];
        foreach ($rows as $row) {
            $description = trim(preg_replace('/\s{2,}/', ' ', $this->stripDiacritics($row['description'])) ?? '');
            $transactions[] = new PdfStatementTransaction(
                $row['date'],
                $description,
                $row['credit'] ? '0.00' : $row['amount'],
                $row['credit'] ? $row['amount'] : '0.00',
                $row['reference'],
                null,
                $this->counterpartyFromDescription($description),
                null,
                $row['closing'],
                $currency,
                $row['raw'],
            );
        }
        $opening = $rows[0]['opening'] ?? null;
        $lastClosing = $rows === [] ? null : $rows[count($rows) - 1]['closing'];
        if ($mismatch = $this->closingMismatch($printedFinal, $lastClosing)) {
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
            $printedFinal ?? $lastClosing,
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
        $t = $this->normTokens($line);
        $desc = false;
        $bani = 0;
        foreach ($t as $tok) {
            if (str_starts_with($tok, 'descriere')) {
                $desc = true;
            }
            if ($tok === 'bani') {
                $bani++;
            }
        }

        return $desc && $bani >= 2 && in_array('sold', $t, true);
    }

    /**
     * Right-aligned amount: first word whose right edge is within 4pt of $right and parses as money.
     *
     * @param PdfWord[] $line
     */
    private function amountAt(array $line, float $right): ?string
    {
        foreach ($line as $w) {
            if (abs($w->x1 - $right) <= 4.0) {
                $v = $this->moneyBySeparator($w->text);
                if ($v !== null) {
                    return $v;
                }
            }
        }

        return null;
    }

    /**
     * "RON pe 31 martie 2025   1.234,56 RON": first line starting with "<CCY> pe" and ending
     * with "<amount> <CCY>".
     *
     * @param array<int, PdfWord[]> $lines
     */
    private function printedFinalBalance(array $lines, string $currency): ?string
    {
        foreach ($lines as $line) {
            $n = count($line);
            if ($n < 3) {
                continue;
            }
            if (strcasecmp($line[0]->text, $currency) !== 0 || strcasecmp($line[1]->text, 'pe') !== 0 || strcasecmp($line[$n - 1]->text, $currency) !== 0) {
                continue;
            }
            $v = $this->moneyBySeparator($line[$n - 2]->text);
            if ($v !== null) {
                return $v;
            }
        }

        return null;
    }

    /**
     * "Titularul contului   Număr de cont / IBAN" label row: the holder is the left value on the next row.
     *
     * @param array<int, PdfWord[]> $lines
     */
    private function holder(array $lines): ?string
    {
        foreach ($lines as $i => $line) {
            $label = null;
            foreach ($line as $w) {
                if (stripos($w->text, 'Titularul') === 0) {
                    $label = $w;
                    break;
                }
            }
            if ($label === null) {
                continue;
            }
            $rightBound = INF;
            foreach ($line as $w) {
                if (stripos($w->text, 'Num') === 0 || strcasecmp($w->text, 'IBAN') === 0) {
                    $rightBound = $w->x0 - 8.0;
                    break;
                }
            }
            if (!isset($lines[$i + 1])) {
                return null;
            }
            $parts = [];
            foreach ($lines[$i + 1] as $w) {
                if ($w->x0 < $rightBound) {
                    $parts[] = $w->text;
                }
            }
            $name = trim(implode(' ', $parts));

            return $name === '' ? null : $name;
        }

        return null;
    }

    /**
     * "Extras de cont RON": the ISO code following those three words.
     *
     * @param PdfWord[] $words
     */
    private function currency(array $words): string
    {
        foreach ($this->clusterer->cluster($words, 2.5) as $line) {
            $n = count($line);
            for ($i = 0; $i + 2 < $n; $i++) {
                if (strcasecmp($line[$i]->text, 'Extras') === 0 && strcasecmp($line[$i + 1]->text, 'de') === 0 && stripos($line[$i + 2]->text, 'cont') === 0) {
                    for ($j = $i + 3; $j < $n; $j++) {
                        $iso = strtoupper(trim($line[$j]->text));
                        if (in_array($iso, self::NEO_ISO_EXT, true)) {
                            return $iso;
                        }
                    }
                }
            }
        }

        return $this->firstIsoCurrency($words, self::NEO_ISO_EXT) ?? 'RON';
    }

    /**
     * @param PdfWord[] $words
     * @param callable(string): bool $match receives the normalised text
     */
    private function findWord(array $words, callable $match): ?PdfWord
    {
        foreach ($words as $w) {
            if ($match($this->normalise($w->text))) {
                return $w;
            }
        }

        return null;
    }
}
