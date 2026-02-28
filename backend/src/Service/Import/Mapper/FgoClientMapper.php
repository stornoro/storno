<?php

namespace App\Service\Import\Mapper;

/**
 * Column mapper for client exports produced by FGO (Facturare Gratuita Online).
 *
 * FGO is distinctive because it merges CUI and CNP into a single column
 * named "CUI/CNP". The mapRow logic in the base class handles the RO-prefix
 * detection, but the persister or a caller may need to further split that
 * value into separate cui/cnp fields when the value is numeric-only and 13
 * digits long (CNP). The two anchor columns used for detection are:
 *  - "Denumire"
 *  - "CUI/CNP"
 */
class FgoClientMapper extends AbstractClientMapper
{
    public function getSource(): string
    {
        return 'fgo';
    }

    /**
     * @return array<string, string>
     */
    public function getDefaultMapping(): array
    {
        return [
            'Denumire'        => 'name',
            'CUI/CNP'         => 'cui',
            'Nr. Reg. Com.'   => 'registrationNumber',
            'Adresa'          => 'address',
            'Localitate'      => 'city',
            'Judet'           => 'county',
            'Cod Postal'      => 'postalCode',
            'Email'           => 'email',
            'Telefon'         => 'phone',
            'Banca'           => 'bankName',
            'Cont bancar'     => 'bankAccount',
            'Persoana contact' => 'contactPerson',
        ];
    }

    /**
     * Returns ratio of FGO-specific headers found in the file.
     *
     * @param string[] $headers
     */
    public function detectConfidence(array $headers): float
    {
        $anchors = ['Denumire', 'CUI/CNP'];

        return $this->ratioFound($anchors, $headers);
    }

    /**
     * Extends base mapRow to additionally handle the FGO "CUI/CNP" merged field.
     *
     * When the raw value mapped to `cui` is exactly 13 numeric digits, it is a
     * CNP (personal numeric code for individuals). In that case the value is
     * moved to the `cnp` field and `type` is set to 'individual'.
     *
     * @param array<string, string> $row
     * @param array<string, string> $columnMapping
     * @return array<string, mixed>
     */
    public function mapRow(array $row, array $columnMapping): array
    {
        $result = parent::mapRow($row, $columnMapping);

        // Detect CNP masquerading as CUI (13 digits, no RO prefix).
        if (
            isset($result['cui'])
            && !isset($result['cnp'])
            && preg_match('/^\d{13}$/', (string) $result['cui'])
        ) {
            $result['cnp']  = $result['cui'];
            $result['type'] = 'individual';
            unset($result['cui']);
        }

        return $result;
    }

    /**
     * @param string[] $expected
     * @param string[] $headers
     */
    private function ratioFound(array $expected, array $headers): float
    {
        if ($expected === []) {
            return 0.0;
        }

        $found = 0;
        foreach ($expected as $column) {
            if (in_array($column, $headers, true)) {
                $found++;
            }
        }

        return round($found / count($expected), 2);
    }
}
