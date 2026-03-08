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

    private const CODE_TO_NAME_RO = [
        'AB' => 'Alba', 'AG' => 'Argeș', 'AR' => 'Arad', 'B' => 'București',
        'BC' => 'Bacău', 'BH' => 'Bihor', 'BN' => 'Bistrița-Năsăud',
        'BR' => 'Brăila', 'BT' => 'Botoșani', 'BV' => 'Brașov', 'BZ' => 'Buzău',
        'CJ' => 'Cluj', 'CL' => 'Călărași', 'CS' => 'Caraș-Severin',
        'CT' => 'Constanța', 'CV' => 'Covasna', 'DB' => 'Dâmbovița', 'DJ' => 'Dolj',
        'GJ' => 'Gorj', 'GL' => 'Galați', 'GR' => 'Giurgiu',
        'HD' => 'Hunedoara', 'HR' => 'Harghita', 'IF' => 'Ilfov',
        'IL' => 'Ialomița', 'IS' => 'Iași', 'MH' => 'Mehedinți',
        'MM' => 'Maramureș', 'MS' => 'Mureș', 'NT' => 'Neamț', 'OT' => 'Olt',
        'PH' => 'Prahova', 'SB' => 'Sibiu', 'SJ' => 'Sălaj', 'SM' => 'Satu Mare',
        'SV' => 'Suceava', 'TL' => 'Tulcea', 'TM' => 'Timiș',
        'TR' => 'Teleorman', 'VL' => 'Vâlcea', 'VN' => 'Vrancea', 'VS' => 'Vaslui',
    ];

    private const CODE_TO_NAME_EN = [
        'AB' => 'Alba', 'AG' => 'Arges', 'AR' => 'Arad', 'B' => 'Bucharest',
        'BC' => 'Bacau', 'BH' => 'Bihor', 'BN' => 'Bistrita-Nasaud',
        'BR' => 'Braila', 'BT' => 'Botosani', 'BV' => 'Brasov', 'BZ' => 'Buzau',
        'CJ' => 'Cluj', 'CL' => 'Calarasi', 'CS' => 'Caras-Severin',
        'CT' => 'Constanta', 'CV' => 'Covasna', 'DB' => 'Dambovita', 'DJ' => 'Dolj',
        'GJ' => 'Gorj', 'GL' => 'Galati', 'GR' => 'Giurgiu',
        'HD' => 'Hunedoara', 'HR' => 'Harghita', 'IF' => 'Ilfov',
        'IL' => 'Ialomita', 'IS' => 'Iasi', 'MH' => 'Mehedinti',
        'MM' => 'Maramures', 'MS' => 'Mures', 'NT' => 'Neamt', 'OT' => 'Olt',
        'PH' => 'Prahova', 'SB' => 'Sibiu', 'SJ' => 'Salaj', 'SM' => 'Satu Mare',
        'SV' => 'Suceava', 'TL' => 'Tulcea', 'TM' => 'Timis',
        'TR' => 'Teleorman', 'VL' => 'Valcea', 'VN' => 'Vrancea', 'VS' => 'Vaslui',
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
     * Convert county ISO code to full name (e.g. "AB" → "Alba", "CJ" → "Cluj").
     * Uses locale for proper diacritics: 'ro' → "București", 'en' → "Bucharest".
     * Unknown values pass through unchanged.
     */
    public static function countyToName(string $county, string $locale = 'ro'): string
    {
        $upper = strtoupper(trim($county));
        $map = $locale === 'ro' ? self::CODE_TO_NAME_RO : self::CODE_TO_NAME_EN;
        return $map[$upper] ?? $county;
    }

    /**
     * Normalize Bucharest county and city values for DB storage.
     * County is stored as "B", city as "SECTOR1"-"SECTOR6".
     * If city doesn't contain a sector, tries to extract it from address.
     *
     * @return array{county: string, city: string}
     */
    public static function normalizeBucharest(string $county, string $city, ?string $address = null): array
    {
        // First normalize county to ISO code
        $county = self::normalizeCounty($county);

        if (in_array(strtoupper($county), self::BUCHAREST_COUNTY_VARIANTS, true)) {
            $sector = self::normalizeBucharestSector($city, $address);
            return [
                'county' => 'B',
                'city' => $sector,
            ];
        }

        return ['county' => $county, 'city' => $city];
    }

    /**
     * Normalize Bucharest city to UBL-compliant SECTOR1-SECTOR6 format.
     * If city is empty or doesn't contain a sector, tries to extract from address.
     */
    public static function normalizeBucharestSector(?string $city, ?string $address = null): string
    {
        // Try to extract sector from city first
        if ($city !== null && $city !== '') {
            if (preg_match('/sect(?:or(?:ul)?|\.?)\s*(\d)/i', $city, $m)) {
                return 'SECTOR' . $m[1];
            }
        }

        // Fallback: try to extract sector from address
        if ($address !== null && $address !== '') {
            if (preg_match('/sect(?:or(?:ul)?|\.?)\s*(\d)/i', $address, $m)) {
                return 'SECTOR' . $m[1];
            }
        }

        // If city is a Bucharest variant (not a sector), default to SECTOR1
        if ($city !== null && $city !== '') {
            $upperCity = strtoupper(trim($city));
            if (in_array($upperCity, self::BUCHAREST_COUNTY_VARIANTS, true)
                || in_array($upperCity, ['MUN. BUCURESTI', 'MUN. BUCHAREST', 'MUNICIPIUL BUCURESTI', 'MUN.BUCURESTI'], true)
            ) {
                return 'SECTOR1';
            }
            // Non-Bucharest city value, return as-is
            return $city;
        }

        return 'SECTOR1';
    }
}
