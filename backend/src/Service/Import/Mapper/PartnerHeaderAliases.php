<?php

namespace App\Service\Import\Mapper;

/**
 * The usual names of the columns of a partner list (clients or suppliers), whatever program
 * wrote it and whether it is a spreadsheet or an XML file: "Denumire furnizor", "Cod fiscal",
 * "cod_fiscal", "Reg_com", "Localitate", "Cont_banca", "Tel"…
 */
final class PartnerHeaderAliases
{
    /** Compared without case, diacritics or punctuation. */
    private const ALIASES = [
        'name'               => ['nume', 'denumire', 'client', 'nume client', 'denumire client', 'furnizor', 'denumire furnizor', 'nume furnizor', 'partener', 'denumire partener', 'nume firma', 'denumire firma', 'firma', 'companie', 'name', 'company', 'customer', 'supplier', 'supplier name'],
        'cui'                => ['cui', 'cif', 'cui cif', 'cif cui', 'cui cnp', 'cif cnp', 'cod fiscal', 'cod unic', 'cod unic de inregistrare', 'cod tva', 'vat', 'vat number', 'tax id'],
        'registrationNumber' => ['nr reg com', 'reg com', 'reg comert', 'reg comertului', 'nr reg comert', 'nr registrul comertului', 'registrul comertului', 'nr inregistrare', 'j', 'registration number'],
        'address'            => ['adresa', 'sediu', 'sediul', 'strada', 'address'],
        'city'               => ['oras', 'localitate', 'localitatea', 'city'],
        'county'             => ['judet', 'judetul', 'county', 'region'],
        'country'            => ['tara', 'country', 'cod tara'],
        'postalCode'         => ['cod postal', 'zip', 'postal code', 'zip code'],
        'email'              => ['email', 'e mail', 'mail', 'adresa email'],
        'phone'              => ['telefon', 'tel', 'phone', 'mobil'],
        'bankName'           => ['banca', 'bank', 'nume banca'],
        'bankAccount'        => ['iban', 'cont', 'cont bancar', 'cont banca', 'cont iban', 'bank account'],
        'contactPerson'      => ['persoana contact', 'persoana de contact', 'contact', 'delegat'],
        'clientCode'         => ['cod', 'cod client', 'cod partener', 'cod furnizor'],
        'defaultPaymentTermDays' => ['termen plata', 'termen de plata', 'termen plata zile', 'zile scadenta'],
        'notes'              => ['observatii', 'note', 'notes', 'mentiuni', 'detalii', 'informatii'],
    ];

    public static function guess(string $header): ?string
    {
        $normalized = self::normalize($header);
        if ($normalized === '') {
            return null;
        }
        foreach (self::ALIASES as $target => $aliases) {
            if (in_array($normalized, $aliases, true)) {
                return $target;
            }
        }

        return null;
    }

    public static function normalize(string $header): string
    {
        $ascii = strtr(mb_strtolower(trim($header)), [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
        ]);
        $ascii = preg_replace('/\([^)]*\)/', ' ', $ascii) ?? $ascii;
        $ascii = preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? '';

        return trim($ascii);
    }

    /**
     * A mapping for the headers a file actually has: the program's own column names first
     * (matched whatever their case or punctuation), the usual names for the rest.
     *
     * @param string[]              $headers
     * @param array<string, string> $programMapping the mapper's static sourceColumn => targetField
     * @param string[]              $allowedTargets
     * @return array<string, string>
     */
    public static function suggest(array $headers, array $programMapping, array $allowedTargets): array
    {
        $byNormalizedName = [];
        foreach ($programMapping as $column => $target) {
            $byNormalizedName[self::normalize((string) $column)] ??= $target;
        }

        $mapping = [];
        foreach ($headers as $header) {
            $header = (string) $header;
            $target = $programMapping[$header] ?? $byNormalizedName[self::normalize($header)] ?? null;
            if ($target !== null && in_array($target, $allowedTargets, true) && !in_array($target, $mapping, true)) {
                $mapping[$header] = $target;
            }
        }
        foreach ($headers as $header) {
            $header = (string) $header;
            if (isset($mapping[$header])) {
                continue;
            }
            $target = self::guess($header);
            if ($target !== null && in_array($target, $allowedTargets, true) && !in_array($target, $mapping, true)) {
                $mapping[$header] = $target;
            }
        }

        return $mapping;
    }
}
