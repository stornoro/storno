<?php

declare(strict_types=1);

namespace App\Util;

/**
 * One canonical key per unit of measure, whatever a line carries: the Romanian labels the
 * apps save ("buc", "ora", "luna"), their plurals and spellings, or the UN/ECE codes that
 * e-Factura invoices come with ("H87", "C62", "HUR", "MON"). The key is what the PDF
 * translates (`units.<key>` in the pdf domain); a value nobody recognises is printed as is.
 */
final class UnitOfMeasure
{
    /** label or code (lower-case) → canonical key */
    private const KEYS = [
        // pieces
        'buc' => 'buc', 'bucata' => 'buc', 'bucati' => 'buc', 'bucăți' => 'buc', 'bucată' => 'buc', 'h87' => 'buc', 'c62' => 'buc', 'ea' => 'buc', 'pcs' => 'buc', 'pc' => 'buc', 'piece' => 'buc', 'pieces' => 'buc', 'stk' => 'buc', 'stück' => 'buc', 'unit' => 'buc', 'units' => 'buc',
        'kg' => 'kg', 'kilogram' => 'kg', 'kgm' => 'kg',
        'g' => 'g', 'gram' => 'g', 'grame' => 'g', 'grm' => 'g',
        'l' => 'l', 'litru' => 'l', 'litri' => 'l', 'ltr' => 'l',
        'ml' => 'ml', 'mlt' => 'ml',
        'm' => 'm', 'metru' => 'm', 'metri' => 'm', 'mtr' => 'm',
        'cm' => 'cm', 'cmt' => 'cm',
        'km' => 'km', 'kmt' => 'km',
        'mp' => 'mp', 'm2' => 'mp', 'mtk' => 'mp',
        'mc' => 'mc', 'm3' => 'mc', 'mtq' => 'mc',
        't' => 't', 'tona' => 't', 'tone' => 't', 'tne' => 't',
        'ora' => 'ora', 'ore' => 'ora', 'oră' => 'ora', 'h' => 'ora', 'hur' => 'ora', 'hour' => 'ora', 'hours' => 'ora',
        'min' => 'min', 'minut' => 'min', 'minute' => 'min',
        'zi' => 'zi', 'zile' => 'zi', 'day' => 'zi', 'days' => 'zi',
        'sapt' => 'sapt', 'saptamana' => 'sapt', 'saptamani' => 'sapt', 'săptămână' => 'sapt', 'wee' => 'sapt', 'week' => 'sapt',
        'luna' => 'luna', 'luni' => 'luna', 'lună' => 'luna', 'mon' => 'luna', 'month' => 'luna', 'months' => 'luna', 'luna - mon' => 'luna',
        'an' => 'an', 'ani' => 'an', 'ann' => 'an', 'year' => 'an', 'years' => 'an',
        'set' => 'set',
        'pereche' => 'pereche', 'perechi' => 'pereche', 'per' => 'pereche', 'pr' => 'pereche', 'pair' => 'pereche',
        'pachet' => 'pachet', 'pachete' => 'pachet', 'pac' => 'pachet', 'xpk' => 'pachet', 'pk' => 'pachet', 'pack' => 'pachet',
        'cutie' => 'cutie', 'cutii' => 'cutie', 'xbx' => 'cutie', 'box' => 'cutie',
        'kwh' => 'kwh', 'mwh' => 'mwh',
        'serv' => 'serv', 'serviciu' => 'serv', 'servicii' => 'serv', 'e48' => 'serv', 'service' => 'serv',
        'proc' => 'proc', '%' => 'proc', 'p1' => 'proc', 'procent' => 'proc',
        'nil' => 'buc',   // ANAF's "nil" unit on some supplier invoices: a plain count
        'c79' => 'kwh',   // UN/ECE C79 = kilowatt
        'ftq' => 'ftq',
    ];

    /** Keys that have a translation in the pdf domain. */
    public const TRANSLATED = ['buc', 'kg', 'g', 'l', 'ml', 'm', 'cm', 'km', 'mp', 'mc', 't', 'ora', 'min', 'zi', 'sapt', 'luna', 'an', 'set', 'pereche', 'pachet', 'cutie', 'kwh', 'mwh', 'serv', 'proc', 'ftq'];

    public static function canonical(?string $raw): ?string
    {
        $key = mb_strtolower(trim((string) $raw));
        if ($key === '') {
            return null;
        }

        return self::KEYS[$key] ?? null;
    }
}
