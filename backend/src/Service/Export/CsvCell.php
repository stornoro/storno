<?php

namespace App\Service\Export;

/**
 * Neutralises spreadsheet formula injection in CSV exports.
 *
 * Excel / LibreOffice evaluate any cell starting with "=", "+", "-" or "@"
 * (and, after a tab / carriage return, the same characters) as a formula.
 * User-supplied strings (client names, addresses, notes...) are therefore
 * prefixed with a single quote so the spreadsheet treats them as literal text.
 * Plain numeric values (e.g. "-12.50") are left untouched.
 */
final class CsvCell
{
    private const TRIGGER_CHARS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * @param mixed $value Raw cell value as passed to fputcsv()
     * @return mixed The value, prefixed with "'" when it would otherwise be evaluated
     */
    public static function neutralize(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        if (!in_array($value[0], self::TRIGGER_CHARS, true)) {
            return $value;
        }

        // A plain number such as "-12.50" or "+40" is data, not a formula.
        if (is_numeric($value)) {
            return $value;
        }

        return "'" . $value;
    }

    /**
     * Neutralise every cell of a CSV row.
     *
     * @param array<int|string, mixed> $row
     * @return array<int|string, mixed>
     */
    public static function neutralizeRow(array $row): array
    {
        return array_map([self::class, 'neutralize'], $row);
    }
}
