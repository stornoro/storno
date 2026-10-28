<?php

declare(strict_types=1);

namespace App\Util;

/**
 * An amount written out in Romanian, the way a chitanță prints it:
 * 1.234,56 RON → "una mie două sute treizeci și patru lei și cincizeci și șase bani".
 */
final class RomanianAmountInWords
{
    private const UNITS = ['', 'unu', 'doi', 'trei', 'patru', 'cinci', 'șase', 'șapte', 'opt', 'nouă'];
    private const UNITS_F = ['', 'una', 'două', 'trei', 'patru', 'cinci', 'șase', 'șapte', 'opt', 'nouă'];
    private const TEENS = ['zece', 'unsprezece', 'doisprezece', 'treisprezece', 'paisprezece', 'cincisprezece', 'șaisprezece', 'șaptesprezece', 'optsprezece', 'nouăsprezece'];
    private const TEENS_F = ['zece', 'unsprezece', 'douăsprezece', 'treisprezece', 'paisprezece', 'cincisprezece', 'șaisprezece', 'șaptesprezece', 'optsprezece', 'nouăsprezece'];
    private const TENS = ['', '', 'douăzeci', 'treizeci', 'patruzeci', 'cincizeci', 'șaizeci', 'șaptezeci', 'optzeci', 'nouăzeci'];

    /** Currency → [singular, plural, feminine?] for the main unit and the subunit. */
    private const CURRENCIES = [
        'RON' => [['leu', 'lei', false], ['ban', 'bani', false]],
        'EUR' => [['euro', 'euro', false], ['cent', 'cenți', false]],
        'USD' => [['dolar', 'dolari', false], ['cent', 'cenți', false]],
        'GBP' => [['liră', 'lire', true], ['penny', 'pence', false]],
    ];

    public static function amount(string|float $amount, string $currency = 'RON'): string
    {
        $currency = strtoupper($currency);
        [$main, $sub] = self::CURRENCIES[$currency] ?? [[$currency, $currency, false], ['subunități', 'subunități', false]];
        $cents = (int) round(abs((float) $amount) * 100);
        $whole = intdiv($cents, 100);
        $rest = $cents % 100;

        $words = self::number($whole, $main[2]) . ' ' . ($whole === 1 ? $main[0] : $main[1]);
        if ($rest > 0) {
            $words .= ' și ' . self::number($rest, $sub[2]) . ' ' . ($rest === 1 ? $sub[0] : $sub[1]);
        }
        if ((float) $amount < 0) {
            $words = 'minus ' . $words;
        }

        return $words;
    }

    /** A whole number in words; feminine agreement for 1 / 2 ("una", "două") when the noun is feminine. */
    public static function number(int $n, bool $feminine = false): string
    {
        if ($n === 0) {
            return 'zero';
        }
        $parts = [];
        $billions = intdiv($n, 1_000_000_000);
        $millions = intdiv($n % 1_000_000_000, 1_000_000);
        $thousands = intdiv($n % 1_000_000, 1000);
        $units = $n % 1000;

        if ($billions > 0) {
            $parts[] = self::group($billions, false, 'un miliard', 'miliarde', 'de miliarde');
        }
        if ($millions > 0) {
            $parts[] = self::group($millions, false, 'un milion', 'milioane', 'de milioane');
        }
        if ($thousands > 0) {
            $parts[] = self::group($thousands, true, 'una mie', 'mii', 'de mii');
        }
        if ($units > 0) {
            $parts[] = self::belowThousand($units, $feminine);
        }

        return implode(' ', $parts);
    }

    /**
     * "un milion", "două milioane", "douăzeci de milioane", "o sută de mii", but "o sută una mii":
     * the noun takes "de" when the figure before it ends in 20–99 or in a round hundred.
     */
    private static function group(int $count, bool $feminine, string $one, string $few, string $many): string
    {
        if ($count === 1) {
            return $one;
        }
        $last = $count % 100;
        $noun = ($last === 0 || $last >= 20) ? $many : $few;

        return self::belowThousand($count, $feminine) . ' ' . $noun;
    }

    private static function belowThousand(int $n, bool $feminine): string
    {
        $parts = [];
        $hundreds = intdiv($n, 100);
        $rest = $n % 100;
        if ($hundreds === 1) {
            $parts[] = 'una sută';
        } elseif ($hundreds > 1) {
            $parts[] = self::UNITS_F[$hundreds] . ' sute';
        }
        if ($rest >= 20) {
            $tens = self::TENS[intdiv($rest, 10)];
            $unit = $rest % 10;
            $parts[] = $unit === 0 ? $tens : $tens . ' și ' . ($feminine ? self::UNITS_F[$unit] : self::UNITS[$unit]);
        } elseif ($rest >= 10) {
            $parts[] = $feminine ? self::TEENS_F[$rest - 10] : self::TEENS[$rest - 10];
        } elseif ($rest > 0) {
            $parts[] = $feminine ? self::UNITS_F[$rest] : self::UNITS[$rest];
        }

        return implode(' ', $parts);
    }
}
