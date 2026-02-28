<?php

namespace App\Util;

class AddressNormalizer
{
    private const BUCHAREST_COUNTY_VARIANTS = ['B', 'BUCURESTI', 'BUCHAREST'];

    private const NAME_TO_CODE = [
        'ALBA' => 'AB', 'ARGES' => 'AG', 'ARAD' => 'AR', 'BUCURESTI' => 'B',
        'BACAU' => 'BC', 'BIHOR' => 'BH', 'BISTRITA-NASAUD' => 'BN',
        'BRAILA' => 'BR', 'BOTOSANI' => 'BT', 'BRASOV' => 'BV', 'BUZAU' => 'BZ',
        'CLUJ' => 'CJ', 'CALARASI' => 'CL', 'CARAS-SEVERIN' => 'CS',
        'CONSTANTA' => 'CT', 'COVASNA' => 'CV', 'DAMBOVITA' => 'DB', 'DOLJ' => 'DJ',
        'GORJ' => 'GJ', 'GALATI' => 'GL', 'GIURGIU' => 'GR',
        'HUNEDOARA' => 'HD', 'HARGHITA' => 'HR', 'ILFOV' => 'IF',
        'IALOMITA' => 'IL', 'IASI' => 'IS', 'MEHEDINTI' => 'MH',
        'MARAMURES' => 'MM', 'MURES' => 'MS', 'NEAMT' => 'NT', 'OLT' => 'OT',
        'PRAHOVA' => 'PH', 'SIBIU' => 'SB', 'SALAJ' => 'SJ', 'SATU MARE' => 'SM',
        'SUCEAVA' => 'SV', 'TULCEA' => 'TL', 'TIMIS' => 'TM',
        'TELEORMAN' => 'TR', 'VALCEA' => 'VL', 'VRANCEA' => 'VN', 'VASLUI' => 'VS',
    ];

    private const VALID_CODES = [
        'AB', 'AG', 'AR', 'B', 'BC', 'BH', 'BN', 'BR', 'BT', 'BV', 'BZ',
        'CJ', 'CL', 'CS', 'CT', 'CV', 'DB', 'DJ', 'GJ', 'GL', 'GR',
        'HD', 'HR', 'IF', 'IL', 'IS', 'MH', 'MM', 'MS', 'NT', 'OT',
        'PH', 'SB', 'SJ', 'SM', 'SV', 'TL', 'TM', 'TR', 'VL', 'VN', 'VS',
    ];

    /**
     * Normalize county to ISO 3166-2:RO code (e.g. "Alba" → "AB", "Bucuresti" → "B").
     * If already a valid code, returns as-is. Unknown values pass through unchanged.
     */
    public static function normalizeCounty(string $county): string
    {
        $upper = strtoupper(trim($county));

        // Strip "RO-" prefix if present
        if (str_starts_with($upper, 'RO-')) {
            $upper = substr($upper, 3);
        }

        // Already a valid ISO code
        if (in_array($upper, self::VALID_CODES, true)) {
            return $upper;
        }

        // Full name → ISO code
        return self::NAME_TO_CODE[$upper] ?? $county;
    }

    /**
     * Normalize Bucharest county and city values for DB storage.
     * County is stored as "B", city as "SECTOR1"-"SECTOR6".
     *
     * @return array{county: string, city: string}
     */
    public static function normalizeBucharest(string $county, string $city): array
    {
        // First normalize county to ISO code
        $county = self::normalizeCounty($county);

        if (in_array(strtoupper($county), self::BUCHAREST_COUNTY_VARIANTS, true)) {
            return [
                'county' => 'B',
                'city' => self::normalizeBucharestSector($city),
            ];
        }

        return ['county' => $county, 'city' => $city];
    }

    /**
     * Normalize Bucharest city to UBL-compliant SECTOR1-SECTOR6 format.
     */
    public static function normalizeBucharestSector(?string $city): string
    {
        if ($city === null || $city === '') {
            return 'SECTOR1';
        }

        // Extract sector number from: "Sector 6", "SECTOR6", "Sectorul 3", "SECT. 1",
        // "RO Sector1", "Sector 6 Mun. Bucureşti", etc.
        if (preg_match('/sect(?:or(?:ul)?|\.?)\s*(\d)/i', $city, $m)) {
            return 'SECTOR' . $m[1];
        }

        return $city;
    }
}
