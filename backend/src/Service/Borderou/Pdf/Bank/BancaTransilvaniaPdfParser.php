<?php

namespace App\Service\Borderou\Pdf\Bank;

use App\Service\Borderou\Pdf\AbstractPdfStatementParser;
use App\Service\Borderou\Pdf\PdfStatement;
use App\Service\Borderou\Pdf\PdfStatementNotRecognizedException;
use App\Service\Borderou\Pdf\PdfWord;

/**
 * Banca Transilvania "EXTRAS CONT" PDF (table: Data / Descriere / Debit / Credit,
 * amounts 1,234.56, dates dd/MM/yyyy, balances printed as SOLD ANTERIOR / SOLD FINAL CONT).
 */
class BancaTransilvaniaPdfParser extends AbstractPdfStatementParser
{
    public function getBankKey(): string
    {
        return 'bt';
    }

    public function getBankLabel(): string
    {
        return 'Banca Transilvania';
    }

    public function score(array $page1Words): int
    {
        $text = $this->normalise(implode(' ', $page1Words));
        if (str_contains($text, '@bancatransilvania.ro')) {
            return 100;
        }
        $a = str_contains($text, '5022670');
        $b = str_contains($text, 'transilvania');
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

        $ibanWord = $this->firstWordMatching($words, '/\b[A-Z]{2}\d{2}[A-Z0-9]{10,30}\b/');
        $iban = $ibanWord?->text;
        $currency = $this->currencyAfterWord($words, 'Valuta') ?? $this->firstIsoCurrency($words) ?? 'RON';
        $fiscalCode = $this->fiscalCodeNextTo($words, '/^CUI:$/');
        $holder = $this->holderLeftOfClientLabel($words);

        // --- table -------------------------------------------------------
        $lines = $this->allLines($pages);
        $headerIdx = null;
        foreach ($lines as $i => $line) {
            $w = $line['words'];
            if (count($w) >= 4 && strcasecmp($w[0]->text, 'Data') === 0 && stripos($w[1]->text, 'Descr') === 0
                && $this->hasWord($w, 'Debit') && $this->hasWord($w, 'Credit')) {
                $headerIdx = $i;
                break;
            }
        }
        if ($headerIdx === null || count($lines[$headerIdx]['words']) !== 4) {
            throw new PdfStatementNotRecognizedException('Nu am putut identifica antetul tabelului (Data / Descriere / Debit / Credit) in PDF-ul Banca Transilvania. Este posibil ca formatul extrasului sa fi fost modificat.');
        }
        [$data, , $debit, $credit] = $lines[$headerIdx]['words'];
        $bounds = [-INF, $data->x1 + 5.0, $debit->x0 - 30.0, $credit->x0 - 30.0];

        $cellRows = [];
        for ($i = $headerIdx; $i < count($lines); $i++) {
            $cellRows[] = $this->splitByBoundaries($lines[$i]['words'], $bounds);
        }

        $isHeader = fn (array $c) => count($c) >= 4 && strcasecmp($c[0], 'Data') === 0 && stripos($c[1], 'Descr') === 0
            && in_array('debit', array_map('strtolower', $c), true) && in_array('credit', array_map('strtolower', $c), true);

        $result = $this->walkDebitCreditTable($cellRows, [
            'money' => fn (?string $v) => $this->parseMoneyEn($v),
            'dateFormats' => ['d/m/Y'],
            'startInTable' => false,
            'isHeader' => $isHeader,
            'skip' => function (array $c, string $joined): bool {
                $t = $this->normalise($joined);
                foreach (['banca transilvania', 'swift', 'www.bancatransilvania.ro', '/ 1 /', '/ 2 /'] as $needle) {
                    if (str_contains($t, $needle)) {
                        return true;
                    }
                }
                $d = $this->normalise($c[1] ?? '');
                foreach (['rulaj zi', 'sold final zi', 'rulaj total cont', 'fonduri proprii'] as $needle) {
                    if (str_contains($d, $needle)) {
                        return true;
                    }
                }

                return false;
            },
            'isClosing' => fn (array $c) => str_contains($this->normalise($c[1] ?? ''), 'sold final cont'),
            'closingFrom' => function (array $c): ?string {
                $v = $this->parseMoneyEn($c[3] ?? '');
                if ($v !== null) {
                    return $v;
                }
                $d = $this->parseMoneyEn($c[2] ?? '');

                return $d === null ? null : bcmul($d, '-1', 2);
            },
            'isOpening' => fn (array $c) => str_starts_with($this->normalise($c[1] ?? ''), 'sold anterior')
                || ($this->parseMoneyEn($c[3] ?? '') !== null && trim($c[1] ?? '') === '' && trim($c[2] ?? '') === ''),
            'strictOpening' => true,
            'reference' => static function (string $desc): array {
                $ref = null;
                if (preg_match('/\bREF[:.]\s*([A-Z0-9]+)/i', $desc, $m)) {
                    $ref = $m[1];
                }
                $desc = preg_replace('/\bREF[:.]\s*[A-Z0-9]+/i', '', $desc) ?? $desc;

                return [trim(preg_replace('/\s{2,}/', ' ', $desc) ?? $desc), $ref];
            },
        ]);

        $warnings = $result['warnings'];
        $opening = null;
        if ($result['transactions'] !== []) {
            $first = $result['transactions'][0];
            $opening = bcsub(bcadd($first->balance ?? '0.00', $first->debit, 2), $first->credit, 2);
        }
        if ($mismatch = $this->closingMismatch($result['closing'], $result['running'])) {
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
            $result['closing'],
            null,
            null,
            $warnings,
        )];
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
     * Digits printed to the right of a label word on the same baseline.
     *
     * @param PdfWord[] $words
     */
    protected function fiscalCodeNextTo(array $words, string $labelRegex): ?string
    {
        $label = $this->firstWordMatching($words, $labelRegex);
        if (!$label) {
            return null;
        }
        $candidates = array_filter($words, static fn (PdfWord $w) => abs($w->y1 - $label->y1) < 0.8 && $w->x0 > $label->x1);
        usort($candidates, static fn (PdfWord $a, PdfWord $b) => $a->x0 <=> $b->x0);
        foreach ($candidates as $w) {
            $t = trim($w->text, ':');
            if (preg_match('/^\d+$/', $t)) {
                return $t;
            }
        }

        return null;
    }

    /**
     * BT prints the client name to the left of the "Client:" label, on the band
     * between the Client: line and the CUI: line.
     *
     * @param PdfWord[] $words
     */
    private function holderLeftOfClientLabel(array $words): ?string
    {
        $client = $this->firstWordMatching($words, '/^Client:/i');
        if (!$client) {
            return null;
        }
        $cui = $this->firstWordMatching($words, '/^CUI:/i');
        $rightLimit = $client->x0;
        if ($cui && $cui->x0 < $rightLimit) {
            $rightLimit = $cui->x0;
        }
        $bandTop = $client->y0 - 5.6;             // 5.6pt above the Client: line (y-down)
        $bandBottom = ($cui ?? $client)->y1 + 5.6; // 5.6pt below the CUI: line
        $candidates = array_values(array_filter($words, static fn (PdfWord $w) => $w->x1 <= $rightLimit && $w->y0 >= $bandTop && $w->y1 <= $bandBottom));
        if ($candidates === []) {
            return null;
        }
        $parts = [];
        foreach ($this->clusterer->cluster($candidates, 1.5) as $line) {
            foreach ($line as $w) {
                $t = trim($w->text, ':');
                if ($t !== '') {
                    $parts[] = $t;
                }
            }
        }
        $name = trim(implode(' ', $parts));

        return $name === '' ? null : $name;
    }
}
