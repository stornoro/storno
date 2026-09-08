<?php

namespace App\Service\Borderou\Pdf\Bank;

use App\Service\Borderou\Pdf\AbstractPdfStatementParser;
use App\Service\Borderou\Pdf\PdfPage;
use App\Service\Borderou\Pdf\PdfStatement;
use App\Service\Borderou\Pdf\PdfStatementNotRecognizedException;
use App\Service\Borderou\Pdf\PdfStatementTransaction;
use App\Service\Borderou\Pdf\PdfWord;

/**
 * Intesa Sanpaolo Bank Romania, layout v2 (upper-case header
 * "DATA OPERATIEI / DATA VALUTEI / OPERATIE / CURS BNR / REF.CLIENT / RULAJ / SOLD",
 * amounts "1,234.56 D|C", every page labelled "Cont:"). One statement per account block.
 * Reconciliation problems are reported as warnings with partial results.
 */
class IntesaV2PdfParser extends AbstractPdfStatementParser
{
    private const MONEY_RX = '/^-?[\d,]+\.\d{2}$/';
    private const CONTINUATION_VOCAB = ['DATA', 'VALUTEI', 'BNR', 'OPERATIEI'];

    public function getBankKey(): string
    {
        return 'intesa';
    }

    public function getBankLabel(): string
    {
        return 'Intesa Sanpaolo Bank';
    }

    public function score(array $page1Words): int
    {
        $t = $this->normalise(implode(' ', $page1Words));
        $isIntesa = str_contains($t, 'www.intesasanpaolobank.ro') || str_contains($t, 'intesa sanpaolo bank') || str_contains($t, 'wbanro22xxx');
        $exact = array_map('trim', $page1Words);
        $isV2 = in_array('RULAJ', $exact, true) && in_array('SOLD', $exact, true) && in_array('OPERATIE', $exact, true);

        return ($isIntesa && $isV2) ? 100 : 0;
    }

    public function parse(array $pages): array
    {
        $blocks = $this->splitIntoAccountBlocks($pages);
        if ($blocks === []) {
            throw new PdfStatementNotRecognizedException('Nu am putut identifica niciun cont (Cont: / Cod IBAN:) in extrasul Intesa Sanpaolo. Este posibil ca formatul extrasului sa fi fost modificat.');
        }
        $statements = [];
        foreach ($blocks as $block) {
            $statement = $this->parseBlock($block);
            if ($statement !== null) {
                $statements[] = $statement;
            }
        }
        if ($statements === []) {
            throw new PdfStatementNotRecognizedException('Nu am putut identifica niciun cont cu IBAN valid (Cod IBAN:) in extrasul Intesa Sanpaolo. Este posibil ca formatul extrasului sa fi fost modificat.');
        }

        return $statements;
    }

    // ------------------------------------------------------------------
    // Account blocks
    // ------------------------------------------------------------------

    /**
     * @param PdfPage[] $pages
     * @return array<int, array{cont: string, pages: array<int, array<int, PdfWord[]>>}>
     */
    private function splitIntoAccountBlocks(array $pages): array
    {
        $blocks = [];
        foreach ($pages as $page) {
            $lines = $this->buildLines($page);
            $cont = $this->findLabelledValue($lines, ['Cont:']);
            if ($cont === null) {
                continue;
            }
            if ($blocks !== [] && $blocks[count($blocks) - 1]['cont'] === $cont) {
                $blocks[count($blocks) - 1]['pages'][] = $lines;
            } else {
                $blocks[] = ['cont' => $cont, 'pages' => [$lines]];
            }
        }

        return $blocks;
    }

    /**
     * Lines of one page, footer removed (everything from the "Sediul" line down,
     * or the bottom 45pt when there is no such line).
     *
     * @return array<int, PdfWord[]>
     */
    private function buildLines(PdfPage $page): array
    {
        $lines = $this->clusterer->cluster($page->words, 2.5);
        foreach ($lines as $i => $line) {
            foreach ($line as $w) {
                if ($w->text === 'Sediul') {
                    return array_slice($lines, 0, $i);
                }
            }
        }
        $h = $page->height > 0 ? $page->height : 842.0;
        $out = [];
        foreach ($lines as $line) {
            $bottom = 0.0;
            foreach ($line as $w) {
                $bottom += $w->y1;
            }
            $bottom /= max(1, count($line));
            if (($h - $bottom) > 45.0) {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * First non-empty word after the consecutive label tokens.
     *
     * @param array<int, PdfWord[]> $lines
     * @param string[] $tokens
     */
    private function findLabelledValue(array $lines, array $tokens): ?string
    {
        $len = count($tokens);
        foreach ($lines as $line) {
            $n = count($line);
            for ($i = 0; $i + $len <= $n; $i++) {
                $ok = true;
                for ($j = 0; $j < $len; $j++) {
                    if (strcasecmp($line[$i + $j]->text, $tokens[$j]) !== 0) {
                        $ok = false;
                        break;
                    }
                }
                if (!$ok) {
                    continue;
                }
                for ($k = $i + $len; $k < $n; $k++) {
                    $t = trim($line[$k]->text);
                    if ($t !== '') {
                        return $t;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param array{cont: string, pages: array<int, array<int, PdfWord[]>>} $block
     */
    private function parseBlock(array $block): ?PdfStatement
    {
        $iban = null;
        foreach ($block['pages'] as $lines) {
            $v = $this->findLabelledValue($lines, ['Cod', 'IBAN:']);
            if ($v !== null) {
                $v = strtoupper($v);
                if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $v)) {
                    $iban = $v;
                }
                break;
            }
        }
        if ($iban === null) {
            return null;
        }

        $holder = null;
        $fiscalCode = null;
        $currency = 'RON';
        foreach ($block['pages'] as $lines) {
            foreach ($lines as $line) {
                foreach ($line as $i => $w) {
                    if (stripos($w->text, '(CIF') !== 0) {
                        continue;
                    }
                    $before = trim($this->lineText(array_slice($line, 0, $i)));
                    $holder = $before === '' ? null : $before;
                    for ($k = $i + 1; $k < count($line); $k++) {
                        $t = trim($line[$k]->text, ') ');
                        if ($t !== '') {
                            $fiscalCode = $t;
                            break;
                        }
                    }
                    break 3;
                }
            }
        }
        foreach ($block['pages'] as $lines) {
            $v = $this->findLabelledValue($lines, ['Moneda:']);
            if ($v !== null) {
                $v = strtoupper(rtrim(trim($v), ':;.,'));
                $currency = $v === 'LEI' ? 'RON' : $v;
                break;
            }
        }

        // --- 4.1 scan lines ------------------------------------------------
        $bounds = null;
        $tableLines = [];
        $opening = null;
        $openingFound = false;
        $printedFinal = null;
        $finalFound = false;
        $printedDebit = null;
        $printedCredit = null;
        $turnoverFound = false;
        $stop = false;
        foreach ($block['pages'] as $lines) {
            $seenHeader = false;
            $count = count($lines);
            for ($i = 0; $i < $count; $i++) {
                $line = $lines[$i];
                if ($finalFound) {
                    $turnover = $this->readTurnoverLine($line);
                    if ($turnover !== null) {
                        [$printedDebit, $printedCredit] = $turnover;
                        $turnoverFound = true;
                        $stop = true;
                        break;
                    }
                    continue;
                }
                if ($this->isHeaderLine($line)) {
                    $seenHeader = true;
                    if ($bounds === null) {
                        $next = ($i + 1 < $count && $this->isContinuationLine($lines[$i + 1])) ? $lines[$i + 1] : null;
                        $bounds = $this->boundaries($line, $next);
                    }
                    continue;
                }
                if ($this->isContinuationLine($line)) {
                    continue;
                }
                $sold = $this->readSoldLine($line, 'initial');
                if ($sold !== null) {
                    $opening = $sold;
                    $openingFound = true;
                    continue;
                }
                $sold = $this->readSoldLine($line, 'final');
                if ($sold !== null) {
                    $printedFinal = $sold;
                    $finalFound = true;
                    continue;
                }
                if ($seenHeader) {
                    if ($line[0]->x0 < 25.0) {
                        $stop = true;
                        break;
                    }
                    $tableLines[] = $line;
                }
            }
            if ($stop) {
                break;
            }
        }

        $warnings = [];
        if ($bounds === null) {
            throw new PdfStatementNotRecognizedException('Nu am putut identifica antetul tabelului (DATA OPERATIEI / DATA VALUTEI / OPERATIE / RULAJ / SOLD) in PDF-ul Intesa Sanpaolo. Este posibil ca formatul extrasului sa fi fost modificat.');
        }
        if (!$openingFound) {
            $warnings[] = sprintf('Contul %s: nu am gasit soldul initial tiparit in extras, asa ca nicio tranzactie nu a putut fi validata. Nu s-a importat nicio tranzactie pentru acest cont.', $iban);

            return new PdfStatement($this->getBankKey(), $this->getBankLabel(), $iban, $currency, [], $holder, $fiscalCode, null, $printedFinal, null, null, $warnings);
        }

        // --- 4.2 / 4.3 split and group ----------------------------------------
        $rows = $this->groupIntoRows($tableLines, $bounds);

        // --- 4.4 interpret ------------------------------------------------------
        $transactions = [];
        $running = $opening;
        $sumDebit = '0.00';
        $sumCredit = '0.00';
        $rowNumber = 0;
        $failed = false;
        foreach ($rows as $row) {
            $rowNumber++;
            $read = count($transactions);
            $sign = strtoupper($row['rulajSign']);
            $isCredit = $sign === 'C';
            $isDebit = $sign === 'D';
            $rulaj = $this->parseMoneyEn($row['rulaj']);
            if ((!$isCredit && !$isDebit) || $rulaj === null) {
                $warnings[] = sprintf('Contul %s: randul %d din tabelul de tranzactii nu a putut fi citit corect (suma sau semnul D/C nu au putut fi citite). S-au citit doar primele %d tranzactii ale acestui cont.', $iban, $rowNumber, $read);
                $failed = true;
                break;
            }
            $amount = $this->bcAbs($rulaj);
            $closing = $isCredit ? bcadd($running, $amount, 2) : bcsub($running, $amount, 2);
            $sold = $this->parseMoneyEn($row['sold']);
            if ($sold === null) {
                $warnings[] = sprintf('Contul %s: randul %d din tabelul de tranzactii nu a putut fi citit corect (randul nu are un sold tiparit fata de care sa fie validat). S-au citit doar primele %d tranzactii ale acestui cont.', $iban, $rowNumber, $read);
                $failed = true;
                break;
            }
            $sold = $this->applySign($sold, $row['soldSign']);
            if (bccomp($sold, $closing, 2) !== 0) {
                $warnings[] = sprintf('Contul %s: soldul calculat nu corespunde cu cel tiparit in extras (asteptat %s, calculat %s). S-au citit doar primele %d tranzactii ale acestui cont.', $iban, $sold, $closing, $read);
                $failed = true;
                break;
            }
            if ($isCredit) {
                $sumCredit = bcadd($sumCredit, $amount, 2);
            } else {
                $sumDebit = bcadd($sumDebit, $amount, 2);
            }
            $running = $closing;
            if (bccomp($amount, '0', 2) === 0) {
                continue;
            }
            $date = $this->parseDate($row['dataValutei'], ['d.m.Y']) ?? $this->parseDate($row['dataOperatiei'], ['d.m.Y']);
            if ($date === null) {
                $warnings[] = sprintf('Contul %s: randul %d nu are o data valida.', $iban, $rowNumber);
                continue;
            }
            $transactions[] = new PdfStatementTransaction(
                $date,
                trim($row['description']),
                $isCredit ? '0.00' : $amount,
                $isCredit ? $amount : '0.00',
                trim($row['reference']) === '' ? null : trim($row['reference']),
                null,
                null,
                null,
                $closing,
                null,
                $row['raw'],
            );
        }

        // --- 5. post-checks (first failing check wins) ---------------------------
        if (!$failed) {
            if (!$finalFound) {
                $warnings[] = sprintf('Contul %s: nu am gasit soldul final tiparit in extras, asa ca nu putem confirma ca s-au citit toate tranzactiile acestui cont.', $iban);
            } elseif (bccomp($printedFinal, $running, 2) !== 0) {
                $warnings[] = sprintf('Contul %s: soldul final calculat (%s) nu corespunde cu soldul final tiparit in extras (%s).', $iban, $running, $printedFinal);
            } elseif (!$turnoverFound) {
                $warnings[] = sprintf('Contul %s: nu am gasit sumarul operatiilor (rulaj debitor / rulaj creditor), asa ca nu putem confirma ca s-au citit toate tranzactiile acestui cont.', $iban);
            } elseif (bccomp($sumDebit, $printedDebit, 2) !== 0 || bccomp($sumCredit, $printedCredit, 2) !== 0) {
                $warnings[] = sprintf('Contul %s: rulajele calculate (debitor %s, creditor %s) nu corespund cu cele tiparite in extras (debitor %s, creditor %s).', $iban, $sumDebit, $sumCredit, $printedDebit, $printedCredit);
            }
        }
        if ($transactions === []) {
            $warnings[] = sprintf('Contul %s: nu a fost gasita nicio tranzactie in extras.', $iban);
        }

        return new PdfStatement(
            $this->getBankKey(),
            $this->getBankLabel(),
            $iban,
            $currency,
            $transactions,
            $holder,
            $fiscalCode,
            $opening,
            $printedFinal ?? $running,
            null,
            null,
            $warnings,
        );
    }

    // ------------------------------------------------------------------
    // Table structure
    // ------------------------------------------------------------------

    /**
     * @param PdfWord[] $line
     */
    private function isHeaderLine(array $line): bool
    {
        $texts = array_map(static fn (PdfWord $w) => $w->text, $line);

        return in_array('RULAJ', $texts, true) && in_array('SOLD', $texts, true) && in_array('OPERATIE', $texts, true);
    }

    /**
     * @param PdfWord[] $line
     */
    private function isContinuationLine(array $line): bool
    {
        if ($line === []) {
            return false;
        }
        foreach ($line as $w) {
            if (!in_array($w->text, self::CONTINUATION_VOCAB, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param PdfWord[] $header
     * @param PdfWord[]|null $continuation
     * @return array{description: float, curs: float, reference: float}
     */
    private function boundaries(array $header, ?array $continuation): array
    {
        $operatie = $curs = $ref = null;
        foreach ($header as $w) {
            if ($operatie === null && $w->text === 'OPERATIE') {
                $operatie = $w;
            } elseif ($curs === null && $w->text === 'CURS') {
                $curs = $w;
            } elseif ($ref === null && str_starts_with($w->text, 'REF')) {
                $ref = $w;
            }
        }
        if (!$operatie) {
            throw new PdfStatementNotRecognizedException('Nu am putut identifica coloanele tabelului in PDF-ul Intesa Sanpaolo. Este posibil ca formatul extrasului sa fi fost modificat.');
        }
        $valuteiRight = null;
        if ($continuation !== null) {
            foreach ($continuation as $w) {
                if ($w->text === 'VALUTEI') {
                    $valuteiRight = $w->x1;
                    break;
                }
            }
        }
        $valuteiRight ??= $operatie->x0 - 20.0;
        $cursX = $curs ? $curs->x0 - 5.0 : ($ref ? $ref->x0 - 5.0 : INF);
        $refX = $ref ? $ref->x0 - 5.0 : INF;

        return [
            'description' => ($valuteiRight + $operatie->x0) / 2,
            'curs' => $cursX,
            'reference' => $refX,
        ];
    }

    /**
     * "Sold initial|final ... 1,234.56 C" → signed balance.
     *
     * @param PdfWord[] $line
     */
    private function readSoldLine(array $line, string $kind): ?string
    {
        $n = count($line);
        if ($n < 2 || $this->normalise($line[0]->text) !== 'sold' || !str_starts_with($this->normalise($line[1]->text), $kind)) {
            return null;
        }
        $last = $line[$n - 1]->text;
        $sign = null;
        $amount = $last;
        if (($last === 'C' || $last === 'D') && $n >= 3) {
            $sign = $last;
            $amount = $line[$n - 2]->text;
        }
        if (!preg_match('/^(?<amount>-?[\d,]+\.\d{2})(?<sign>[CD])?$/', $amount, $m)) {
            return null;
        }
        $v = $this->parseMoneyEn($m['amount']);
        if ($v === null) {
            return null;
        }
        $sign ??= $m['sign'] ?? '';

        return $this->applySign($v, $sign);
    }

    private function applySign(string $amount, string $sign): string
    {
        if (strtoupper($sign) === 'D') {
            return bcmul($amount, '-1', 2);
        }

        return $amount;
    }

    /**
     * "dd-MM-yyyy dd-MM-yyyy ... debit credit net" → [printedDebit, printedCredit].
     *
     * @param PdfWord[] $line
     * @return array{0: string, 1: string}|null
     */
    private function readTurnoverLine(array $line): ?array
    {
        $n = count($line);
        if ($n < 5 || !preg_match('/^\d{2}-\d{2}-\d{4}$/', $line[0]->text) || !preg_match('/^\d{2}-\d{2}-\d{4}$/', $line[1]->text)) {
            return null;
        }
        for ($i = $n - 3; $i < $n; $i++) {
            if (!preg_match(self::MONEY_RX, $line[$i]->text)) {
                return null;
            }
        }
        $debit = $this->parseMoneyEn($line[$n - 3]->text);
        $credit = $this->parseMoneyEn($line[$n - 2]->text);
        if ($debit === null || $credit === null) {
            return null;
        }

        return [$debit, $credit];
    }

    /**
     * @param PdfWord[] $line
     * @param array{description: float, curs: float, reference: float} $bounds
     * @return array{dates: string[], description: string, reference: string, rulaj: string, rulajSign: string, sold: string, soldSign: string}
     */
    private function splitLine(array $line, array $bounds): array
    {
        $rulaj = $rulajSign = $sold = $soldSign = '';
        $n = count($line);
        if ($n >= 4
            && preg_match(self::MONEY_RX, $line[$n - 4]->text)
            && in_array($line[$n - 3]->text, ['C', 'D'], true)
            && preg_match(self::MONEY_RX, $line[$n - 2]->text)
            && in_array($line[$n - 1]->text, ['C', 'D'], true)
        ) {
            $rulaj = $line[$n - 4]->text;
            $rulajSign = $line[$n - 3]->text;
            $sold = $line[$n - 2]->text;
            $soldSign = $line[$n - 1]->text;
            $line = array_slice($line, 0, $n - 4);
        }
        $dates = [];
        $desc = [];
        $ref = [];
        foreach ($line as $w) {
            if ($w->x0 < $bounds['description']) {
                $dates[] = $w->text;
            } elseif ($w->x0 < $bounds['curs']) {
                $desc[] = $w->text;
            } elseif ($w->x0 >= $bounds['reference']) {
                $ref[] = $w->text;
            }
        }

        return [
            'dates' => $dates,
            'description' => implode(' ', $desc),
            'reference' => implode('', $ref),
            'rulaj' => $rulaj,
            'rulajSign' => $rulajSign,
            'sold' => $sold,
            'soldSign' => $soldSign,
        ];
    }

    /**
     * @param array<int, PdfWord[]> $tableLines
     * @param array{description: float, curs: float, reference: float} $bounds
     * @return array<int, array{dataOperatiei: string, dataValutei: string, description: string, reference: string, rulaj: string, rulajSign: string, sold: string, soldSign: string, raw: string[]}>
     */
    private function groupIntoRows(array $tableLines, array $bounds): array
    {
        $rows = [];
        foreach ($tableLines as $line) {
            $parts = $this->splitLine($line, $bounds);
            $dateTokens = array_values(array_filter($parts['dates'], fn (string $t) => $this->looksLikeDate($t)));
            if ($dateTokens !== []) {
                $rows[] = [
                    'dataOperatiei' => $dateTokens[0],
                    'dataValutei' => $dateTokens[count($dateTokens) - 1],
                    'description' => '',
                    'reference' => '',
                    'rulaj' => $parts['rulaj'],
                    'rulajSign' => $parts['rulajSign'],
                    'sold' => $parts['sold'],
                    'soldSign' => $parts['soldSign'],
                    'raw' => [],
                ];
            }
            if ($rows === []) {
                continue;
            }
            $last = count($rows) - 1;
            if (trim($parts['description']) !== '') {
                $rows[$last]['description'] .= ($rows[$last]['description'] === '' ? '' : "\n") . trim($parts['description']);
            }
            $rows[$last]['reference'] .= $parts['reference'];
            $rows[$last]['raw'][] = $this->lineText($line);
        }

        return $rows;
    }
}
