<?php

namespace App\Service\Import\Mapper;

abstract class AbstractClientMapper implements ColumnMapperInterface
{
    public function getImportType(): string
    {
        return 'clients';
    }

    public function getRequiredFields(): array
    {
        return ['name'];
    }

    /**
     * @return array<string, string>
     */
    public function getTargetFields(): array
    {
        return [
            'name'                   => 'Denumire',
            'cui'                    => 'CUI / CIF',
            'cnp'                    => 'CNP',
            'registrationNumber'     => 'Nr. Reg. Comerț',
            'address'                => 'Adresa',
            'city'                   => 'Oraș',
            'county'                 => 'Județ',
            'country'                => 'Țara',
            'postalCode'             => 'Cod poștal',
            'email'                  => 'Email',
            'phone'                  => 'Telefon',
            'bankName'               => 'Banca',
            'bankAccount'            => 'IBAN',
            'defaultPaymentTermDays' => 'Termen plată (zile)',
            'contactPerson'          => 'Persoană contact',
            'clientCode'             => 'Cod client',
            'notes'                  => 'Observații',
            'createdAt'              => 'Data creare',
        ];
    }

    /**
     * Map a raw source row into a normalised array keyed by target field names.
     *
     * Processing rules applied in order:
     *  1. Apply the caller-supplied column mapping.
     *  2. Normalise CUI: strip leading "RO" prefix, store it as vatCode and
     *     mark the client as a VAT payer when the prefix is present.
     *  3. Derive `type` from the presence of CUI (company) vs CNP (individual).
     *  4. Cast defaultPaymentTermDays to int when present.
     *
     * @param array<string, string> $row           Raw row keyed by source column name
     * @param array<string, string> $columnMapping  sourceColumn => targetField
     * @return array<string, mixed>
     */
    public function mapRow(array $row, array $columnMapping): array
    {
        $result = [];

        foreach ($columnMapping as $sourceColumn => $targetField) {
            if ($targetField === '' || $targetField === null) {
                continue;
            }

            $value = $row[$sourceColumn] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $result[$targetField] = $value;
        }

        // --- CUI / VAT code normalisation ---
        if (isset($result['cui'])) {
            $raw = trim((string) $result['cui']);

            // Treat "-" as no CUI (individual person)
            if ($raw === '-' || $raw === '') {
                unset($result['cui']);
                $result['type'] = 'individual';
                if (!isset($result['isVatPayer'])) {
                    $result['isVatPayer'] = false;
                }
            }
        }
        if (isset($result['cui'])) {
            $raw = trim((string) $result['cui']);
            $euPrefixes = [
                'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES',
                'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT',
                'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
            ];
            $prefix = strtoupper(substr($raw, 0, 2));

            if (in_array($prefix, $euPrefixes, true) && strlen($raw) > 2) {
                $fullVat = strtoupper($raw);
                $result['vatCode'] = $fullVat;
                $result['isVatPayer'] = true;

                if ($prefix === 'RO') {
                    // Romanian: CUI = digits only (strip RO prefix)
                    $result['cui'] = trim(substr($raw, 2));
                } else {
                    // Foreign EU: CUI = full VAT number with prefix (e.g. BE1017382520)
                    $result['cui'] = $fullVat;
                }

                // Derive country from prefix if not set
                if (empty($result['country'])) {
                    $result['country'] = $prefix === 'EL' ? 'GR' : $prefix;
                }
            } elseif (preg_match('/^\d+$/', $raw)) {
                // Plain numeric — keep as CUI, not a VAT payer (unless already set)
                $result['cui'] = $raw;
                if (!isset($result['isVatPayer'])) {
                    $result['isVatPayer'] = false;
                }
            } else {
                // Non-numeric, non-EU prefix — not a valid CUI, discard
                unset($result['cui']);
                if (!isset($result['isVatPayer'])) {
                    $result['isVatPayer'] = false;
                }
            }
        }

        // --- Type detection ---
        if (!isset($result['type'])) {
            if (isset($result['cnp']) && $result['cnp'] !== '') {
                $result['type'] = 'individual';
            } else {
                $result['type'] = 'company';
            }
        }

        // --- defaultPaymentTermDays cast ---
        if (isset($result['defaultPaymentTermDays'])) {
            $cast = (int) $result['defaultPaymentTermDays'];
            $result['defaultPaymentTermDays'] = $cast > 0 ? $cast : null;
        }

        return $result;
    }
}
