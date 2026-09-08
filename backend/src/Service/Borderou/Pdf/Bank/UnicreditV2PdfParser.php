<?php

namespace App\Service\Borderou\Pdf\Bank;

/**
 * UniCredit Bank statement with a running-balance column ("Data / Descriere / Debit / Credit / Sold(RON)").
 * Same metadata, sections and money/date handling as the V1 layout; differs in the 5-word
 * header, an extra (unused) balance cell, a looser 6.5pt row clustering and a heavier
 * "bold" weight in the start heuristic.
 */
class UnicreditV2PdfParser extends UnicreditPdfParser
{
    public function score(array $page1Words): int
    {
        [$a, $b, $c, $d] = $this->detectionFlags($page1Words);
        if ($a && $b && $c && $d) {
            return 100;
        }

        return ($a || $b || $c) ? 50 : 0;
    }

    protected function headerWordCount(): int
    {
        return 5;
    }

    protected function lineTolerance(): float
    {
        return 6.5;
    }

    protected function boldWeight(): int
    {
        return 6;
    }

    protected function isHeaderLine(array $header): bool
    {
        if (!parent::isHeaderLine($header)) {
            return false;
        }
        foreach ($header as $w) {
            if (str_starts_with($this->normalise($this->dedup($w->text)), 'sold')) {
                return true;
            }
        }

        return false;
    }

    protected function boundaries(array $header): array
    {
        [$descr, $debit, $credit] = $this->headerAnchors($header);

        return [-INF, $descr->x0, $debit->x0 - 40.0, $credit->x0 - 40.0, $credit->x1 + 16.0];
    }

    /**
     * V2: a start opens a transaction only when the previous line was not a start (two
     * consecutive bold lines form one headline). Because our "bold" stand-in is the dated
     * amount line, two consecutive dated lines are distinct rows, so a start after a
     * transaction that already holds an amount also opens a new one.
     */
    protected function startsNewTransaction(bool $isStart, bool $bold, array &$state, ?array $current, string $desc, bool $hasAmount): bool
    {
        return $isStart && (!$state['prevWasStart'] || ($current !== null && bccomp($current['amount'], '0', 2) > 0));
    }

    protected function headerNotFoundMessage(): string
    {
        return 'Nu am putut identifica antetul tabelului (Data / Descriere / Debit / Credit / Sold) in PDF-ul UniCredit. Este posibil ca formatul extrasului sa fi fost modificat.';
    }
}
