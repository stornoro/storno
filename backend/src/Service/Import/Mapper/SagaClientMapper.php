<?php

namespace App\Service\Import\Mapper;

/**
 * Column mapper for client data exported from SAGA Contabilitate.
 *
 * SAGA XML exports use underscore-separated field names. The three most
 * distinctive columns used for confidence detection are:
 *  - "Denumire"    (client name — shared with some other platforms)
 *  - "Cod_fiscal"  (fiscal code — SAGA-specific underscore naming)
 *  - "Localitate"  (city — SAGA uses "Localitate" instead of "Oras")
 */
class SagaClientMapper extends AbstractClientMapper
{
    public function getSource(): string
    {
        return 'saga';
    }

    /**
     * @return array<string, string>
     */
    public function getDefaultMapping(): array
    {
        return [
            'Denumire'          => 'name',
            'Cod_fiscal'        => 'cui',
            'Reg_comert'        => 'registrationNumber',
            'Adresa'            => 'address',
            'Localitate'        => 'city',
            'Judet'             => 'county',
            'Tara'              => 'country',
            'Cod_postal'        => 'postalCode',
            'Email'             => 'email',
            'Telefon'           => 'phone',
            'Banca'             => 'bankName',
            'Cont_banca'        => 'bankAccount',
            'Persoana_contact'  => 'contactPerson',
            'Cod_partener'      => 'clientCode',
        ];
    }

    /**
     * Returns ratio of SAGA-specific headers found in the file.
     *
     * @param string[] $headers
     */
    public function detectConfidence(array $headers): float
    {
        $anchors = ['Denumire', 'Cod_fiscal', 'Localitate'];

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
