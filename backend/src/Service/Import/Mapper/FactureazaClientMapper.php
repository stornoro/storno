<?php

namespace App\Service\Import\Mapper;

/**
 * Column mapper for client CSV exports produced by Factureaza.
 *
 * Anchor columns for confidence detection:
 *  - "Nume client"
 *  - "CIF"
 *  - "Nr. Reg. Comertului"
 */
class FactureazaClientMapper extends AbstractClientMapper
{
    public function getSource(): string
    {
        return 'factureaza';
    }

    /**
     * @return array<string, string>
     */
    public function getDefaultMapping(): array
    {
        return [
            'Nume client'         => 'name',
            'CIF'                 => 'cui',
            'Nr. Reg. Comertului' => 'registrationNumber',
            'Adresa'              => 'address',
            'Oras'                => 'city',
            'Judet'               => 'county',
            'Tara'                => 'country',
            'Cod postal'          => 'postalCode',
            'Email'               => 'email',
            'Telefon'             => 'phone',
            'Banca'               => 'bankName',
            'IBAN'                => 'bankAccount',
            'Contact'             => 'contactPerson',
            'Cod client'          => 'clientCode',
        ];
    }

    /**
     * @param string[] $headers
     */
    public function detectConfidence(array $headers): float
    {
        $anchors = ['Nume client', 'CIF', 'Nr. Reg. Comertului'];

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
