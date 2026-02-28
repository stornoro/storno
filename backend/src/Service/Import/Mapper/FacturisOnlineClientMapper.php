<?php

namespace App\Service\Import\Mapper;

/**
 * Column mapper for client exports produced by FacturisOnline.
 *
 * FacturisOnline uses "Cod fiscal" (two words, space-separated) for the fiscal
 * code and "Reg. comertului" for the registration number. Together with
 * "Denumire" these three columns form the confidence anchors. Note that
 * "Denumire" is shared with SAGA and FGO, but the combination with
 * "Cod fiscal" (space) makes this format unique.
 */
class FacturisOnlineClientMapper extends AbstractClientMapper
{
    public function getSource(): string
    {
        return 'facturis_online';
    }

    /**
     * @return array<string, string>
     */
    public function getDefaultMapping(): array
    {
        return [
            'Denumire'          => 'name',
            'Cod fiscal'        => 'cui',
            'Reg. comertului'   => 'registrationNumber',
            'Adresa'            => 'address',
            'Oras'              => 'city',
            'Judet'             => 'county',
            'Tara'              => 'country',
            'Email'             => 'email',
            'Telefon'           => 'phone',
            'Banca'             => 'bankName',
            'Cont banca'        => 'bankAccount',
            'Persoana contact'  => 'contactPerson',
        ];
    }

    /**
     * Returns ratio of FacturisOnline-specific headers found in the file.
     *
     * @param string[] $headers
     */
    public function detectConfidence(array $headers): float
    {
        $anchors = ['Denumire', 'Cod fiscal', 'Reg. comertului'];

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
