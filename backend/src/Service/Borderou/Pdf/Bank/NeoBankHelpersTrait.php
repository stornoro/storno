<?php

namespace App\Service\Borderou\Pdf\Bank;

use App\Service\Borderou\Pdf\PdfPage;
use App\Service\Borderou\Pdf\PdfWord;

/**
 * Helpers shared by the fintech / challenger-bank statement parsers
 * (Revolut, Wise, myPOS, Viva, Nexent). Everything speaks the specs' PDF
 * coordinates (origin bottom-left) on the outside and PdfWord's top-left
 * coordinates on the inside.
 */
trait NeoBankHelpersTrait
{
    /** @var string[] ISO whitelist incl. HUF/PLN (Revolut, Wise, Viva) */
    private const NEO_ISO_EXT = ['RON', 'EUR', 'USD', 'GBP', 'CHF', 'JPY', 'AUD', 'CAD', 'SEK', 'NOK', 'DKK', 'HUF', 'PLN'];
    /** @var string[] ISO whitelist without HUF/PLN (myPOS, Nexent) */
    private const NEO_ISO = ['RON', 'EUR', 'USD', 'GBP', 'CHF', 'JPY', 'AUD', 'CAD', 'SEK', 'NOK', 'DKK'];

    /**
     * Page-1 words above the footer ("Top > $minTop"), ordered top-down then left-right.
     *
     * @return PdfWord[]
     */
    private function page1Words(PdfPage $page, float $minTop = 90.0): array
    {
        $words = $this->wordsInBand($page, $minTop);
        usort($words, static fn (PdfWord $a, PdfWord $b) => [$a->y0, $a->x0] <=> [$b->y0, $b->x0]);

        return $words;
    }

    /**
     * Words inside a fixed box given in PDF coordinates: Left >= $left, Right <= $right,
     * Top <= $maxTop, Bottom >= $minBottom (Top/Bottom measured from the page bottom).
     *
     * @return PdfWord[] ordered top-down then left-right
     */
    private function boxWords(PdfPage $page, float $left, float $right, float $maxTop, float $minBottom): array
    {
        $h = $page->height > 0 ? $page->height : 842.0;
        $out = array_values(array_filter(
            $page->words,
            static fn (PdfWord $w) => $w->x0 >= $left && $w->x1 <= $right && ($h - $w->y0) <= $maxTop && ($h - $w->y1) >= $minBottom,
        ));
        usort($out, static fn (PdfWord $a, PdfWord $b) => [$a->y0, $a->x0] <=> [$b->y0, $b->x0]);

        return $out;
    }

    /**
     * Money parser of the "last separator wins" family: keep only digits, '.', ',', signs;
     * when both separators are present the later one is the decimal mark; a lone comma is a
     * decimal mark; a lone dot is a decimal mark. "1.234,56", "1,234.56", "-250,00", "€45.30"
     * all parse; Unicode minus signs are treated as '-'.
     */
    private function moneyBySeparator(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = str_replace(["\u{2212}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{00AD}"], '-', trim($value));
        $v = preg_replace('/[^\d.,\-+]/u', '', $v) ?? '';
        if ($v === '' || !preg_match('/\d/', $v)) {
            return null;
        }
        $lc = strrpos($v, ',');
        $ld = strrpos($v, '.');
        if ($lc !== false && ($ld === false || $lc > $ld)) {
            $v = str_replace('.', '', $v);
            $v = str_replace(',', '.', $v);
        } elseif ($ld !== false && ($lc === false || $ld > $lc)) {
            $v = str_replace(',', '', $v);
        }
        if (!preg_match('/^[+-]?\d+(\.\d+)?$/', $v)) {
            return null;
        }

        return number_format((float) $v, 2, '.', '');
    }

    private function nonZero(?string $amount): bool
    {
        return $amount !== null && bccomp($amount, '0', 2) !== 0;
    }

    /**
     * Rightmost word of a line that parses as money.
     *
     * @param PdfWord[] $words
     */
    private function rightmostMoney(array $words, callable $money): ?string
    {
        for ($i = count($words) - 1; $i >= 0; $i--) {
            $v = $money($words[$i]->text);
            if ($v !== null) {
                return $v;
            }
        }

        return null;
    }

    /**
     * "Has a known token": the normalised line contains the normalised label, or the
     * label's words appear in order as a subsequence of the line's tokens (tokens
     * trimmed of ':', '-', en/em dashes).
     *
     * @param PdfWord[] $words
     */
    private function hasKnownToken(array $words, string $label): bool
    {
        $text = $this->normalise($this->lineText($words));
        $lab = $this->normalise($label);
        if (str_contains($text, $lab)) {
            return true;
        }
        $trim = ":-\u{2013}\u{2014}";
        $tokens = array_map(fn (PdfWord $w) => trim($this->normalise($w->text), $trim), $words);
        $needles = array_values(array_filter(array_map(static fn (string $t) => trim($t, $trim), preg_split('/\s+/', $lab) ?: []), static fn (string $t) => $t !== ''));
        $k = 0;
        foreach ($tokens as $t) {
            if ($k < count($needles) && $t === $needles[$k]) {
                $k++;
            }
        }

        return $needles !== [] && $k === count($needles);
    }

    /**
     * Normalised (lower-case, no diacritics) text of every word of a line.
     *
     * @param PdfWord[] $words
     * @return string[]
     */
    private function normTokens(array $words): array
    {
        return array_map(fn (PdfWord $w) => $this->normalise($w->text), $words);
    }

    /**
     * ISO code of a token ("RON", "eur", "Valuta:RON" no), trailing punctuation ignored; LEI => RON.
     *
     * @param string[] $iso
     */
    private function isoToken(string $text, array $iso): ?string
    {
        $t = strtoupper(trim($text, " :;.,\t"));
        if ($t === 'LEI') {
            return 'RON';
        }

        return in_array($t, $iso, true) ? $t : null;
    }

    /**
     * Romanian diacritics (both cedilla and comma-below forms) and other accents removed.
     */
    private function stripDiacritics(string $text): string
    {
        $text = strtr($text, [
            'ă' => 'a', 'Ă' => 'A', 'â' => 'a', 'Â' => 'A', 'î' => 'i', 'Î' => 'I',
            'ș' => 's', 'Ș' => 'S', 'ş' => 's', 'Ş' => 'S', 'ț' => 't', 'Ț' => 'T', 'ţ' => 't', 'Ţ' => 'T',
        ]);
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $stripped = preg_replace('/\p{Mn}+/u', '', $decomposed);
                if (is_string($stripped)) {
                    $text = \Normalizer::normalize($stripped, \Normalizer::FORM_C) ?: $stripped;
                }
            }
        }

        return $text;
    }

    /**
     * Counterparty name when the description obviously names one
     * ("Payment from X", "Sent money to X", "Plata catre X", "Incasare de la X").
     */
    private function counterpartyFromDescription(string $description): ?string
    {
        $d = trim(preg_replace('/\s{2,}/', ' ', $description) ?? $description);
        if ($d === '') {
            return null;
        }
        $patterns = [
            '/^(?:received money from|money added from|payment from|transfer from|incasare de la|bani primiti de la|primit de la|from|de la)\s+(.+)$/iu',
            '/^(?:sent money to|card payment to|payment to|transfer to|plata catre|trimis catre|to|catre)\s+(.+)$/iu',
            '/\b(?:catre|către|de la)\s+([[:upper:]][^,;]+)$/u',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $d, $m)) {
                $name = trim($m[1]);
                $name = preg_replace('/\s+cu referin[țt][ăa]\s+.*$/iu', '', $name) ?? $name;
                $name = trim($name, " .,;:-");
                if ($name !== '' && mb_strlen($name) <= 120) {
                    return $name;
                }
            }
        }

        return null;
    }
}
