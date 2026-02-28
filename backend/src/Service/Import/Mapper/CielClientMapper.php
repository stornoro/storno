<?php

namespace App\Service\Import\Mapper;

/**
 * Column mapper for client CSV exports produced by Ciel.
 *
 * Anchor columns for confidence detection:
 *  - "Denumire"
 *  - "Cod fiscal"
 *  - "Nr. inregistrare"
 */
class CielClientMapper extends AbstractClientMapper
{
    public function getSource(): string
    {
        return 'ciel';
    }

    /**
     * @return array<string, string>
     */
    public function getDefaultMapping(): array
    {
        return [
            'Denumire'          => 'name',
            'Cod fiscal'        => 'cui',
            'Nr. inregistrare'  => 'registrationNumber',
            'Adresa'            => 'address',
            'Localitate'        => 'city',
            'Judet'             => 'county',
            'Tara'              => 'country',
            'Cod postal'        => 'postalCode',
            'Email'             => 'email',
            'Telefon'           => 'phone',
            'Banca'             => 'bankName',
            'Cont bancar'       => 'bankAccount',
            'Persoana contact'  => 'contactPerson',
            'Cod'               => 'clientCode',
        ];
    }

    /**
     * @param string[] $headers
     */
    public function detectConfidence(array $headers): float
    {
        $anchors = ['Denumire', 'Cod fiscal', 'Nr. inregistrare'];

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
