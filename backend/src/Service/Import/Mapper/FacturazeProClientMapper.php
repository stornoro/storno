<?php

namespace App\Service\Import\Mapper;

/**
 * Column mapper for client CSV exports produced by FacturarePro.
 *
 * Anchor columns for confidence detection:
 *  - "Nume"
 *  - "CUI"
 *  - "Nr. ORC"
 */
class FacturazeProClientMapper extends AbstractClientMapper
{
    public function getSource(): string
    {
        return 'facturare_pro';
    }

    /**
     * @return array<string, string>
     */
    public function getDefaultMapping(): array
    {
        return [
            'Nume'          => 'name',
            'CUI'           => 'cui',
            'Nr. ORC'       => 'registrationNumber',
            'Adresa'        => 'address',
            'Oras'          => 'city',
            'Judet'         => 'county',
            'Tara'          => 'country',
            'Cod postal'    => 'postalCode',
            'Email'         => 'email',
            'Telefon'       => 'phone',
            'Banca'         => 'bankName',
            'IBAN'          => 'bankAccount',
            'Contact'       => 'contactPerson',
            'Cod'           => 'clientCode',
        ];
    }

    /**
     * @param string[] $headers
     */
    public function detectConfidence(array $headers): float
    {
        $anchors = ['Nume', 'CUI', 'Nr. ORC'];

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
