<?php

namespace App\Service\Import\Mapper;

/**
 * Column mapper for client CSV exports produced by IceFact.
 *
 * IceFact only supports CSV format.
 *
 * Anchor columns for confidence detection:
 *  - "Denumire"
 *  - "CIF"
 *  - "Adresa"
 */
class IceFactClientMapper extends AbstractClientMapper
{
    public function getSource(): string
    {
        return 'icefact';
    }

    /**
     * @return array<string, string>
     */
    public function getDefaultMapping(): array
    {
        return [
            'Denumire'         => 'name',
            'CIF'              => 'cui',
            'Nr. Reg. Com.'    => 'registrationNumber',
            'Adresa'           => 'address',
            'Oras'             => 'city',
            'Judet'            => 'county',
            'Tara'             => 'country',
            'Cod postal'       => 'postalCode',
            'Email'            => 'email',
            'Telefon'          => 'phone',
            'Banca'            => 'bankName',
            'IBAN'             => 'bankAccount',
            'Persoana contact' => 'contactPerson',
        ];
    }

    /**
     * @param string[] $headers
     */
    public function detectConfidence(array $headers): float
    {
        $anchors = ['Denumire', 'CIF', 'Adresa'];

        return $this->ratioFound($anchors, $headers);
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
