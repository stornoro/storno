<?php

declare(strict_types=1);

namespace App\Util;

/** Romanian personal numeric code (CNP): 13 digits, first digit 1-8 for residents, weighted control digit. */
final class Cnp
{
    public static function normalize(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    public static function isValid(?string $value): bool
    {
        $d = self::normalize($value);
        if (!preg_match('/^[1-8]\d{12}$/', $d)) {
            return false;
        }
        $w = '279146358279';
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $d[$i] * (int) $w[$i];
        }
        $c = $sum % 11;

        return (int) $d[12] === ($c === 10 ? 1 : $c);
    }

    /** A 13-digit identifier is a natural person (CNP) or a non-resident NIF (starts with 9); a CUI has at most 10 digits. */
    public static function looksLikeNaturalPerson(?string $value): bool
    {
        $d = self::normalize($value);

        return strlen($d) === 13 && $d[0] !== '9';
    }
}
