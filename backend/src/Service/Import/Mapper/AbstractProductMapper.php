<?php

namespace App\Service\Import\Mapper;

/**
 * Shared base for all product import mappers.
 *
 * Normalisation rules applied in mapRow():
 *  - vatRate    : strips trailing '%' and formats to 2 decimal places
 *                 ('19%' → '19.00', '19.5' → '19.50')
 *  - isService  : detects truthy strings from multiple platforms
 *                 ('da', 'yes', '1', 'true', 'serviciu', 'service')
 *  - unitOfMeasure: normalises common Romanian abbreviations to canonical forms
 *  - defaultPrice : strips currency symbols, converts European comma-decimal
 *                  notation to dot-decimal ('1.234,56' → '1234.56')
 *  - currency   : defaults to 'RON' when the column is empty or absent
 *  - usage      : defaults to 'both' when the column is empty or absent
 */
abstract class AbstractProductMapper implements ColumnMapperInterface
{
    public function getImportType(): string
    {
        return 'products';
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
            'name'            => 'Denumire produs',
            'code'            => 'Cod produs',
            'description'     => 'Descriere',
            'unitOfMeasure'   => 'Unitate de măsură',
            'defaultPrice'    => 'Preț',
            'currency'        => 'Moneda',
            'vatRate'         => 'Cota TVA (%)',
            'vatCategoryCode' => 'Categoria TVA',
            'isService'       => 'Este serviciu',
            'usage'           => 'Utilizare',
            'ncCode'          => 'Cod NC',
            'cpvCode'         => 'Cod CPV',
        ];
    }

    /**
     * Map a raw source row into a normalised array keyed by target field names.
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

        // --- vatRate normalisation ---
        if (isset($result['vatRate'])) {
            $result['vatRate'] = $this->normaliseVatRate((string) $result['vatRate']);
        }

        // --- isService normalisation ---
        if (isset($result['isService'])) {
            $result['isService'] = $this->normaliseBoolean((string) $result['isService']);
        }

        // --- isActive normalisation ---
        if (isset($result['isActive'])) {
            $result['isActive'] = $this->normaliseBoolean((string) $result['isActive']);
        }

        // --- unitOfMeasure normalisation ---
        if (isset($result['unitOfMeasure'])) {
            $result['unitOfMeasure'] = $this->normaliseUnitOfMeasure((string) $result['unitOfMeasure']);
        }

        // --- defaultPrice normalisation ---
        if (isset($result['defaultPrice'])) {
            $result['defaultPrice'] = $this->normalisePrice((string) $result['defaultPrice']);
        }

        // --- currency default ---
        if (!isset($result['currency']) || $result['currency'] === '') {
            $result['currency'] = 'RON';
        } else {
            $result['currency'] = strtoupper(trim((string) $result['currency']));
        }

        // --- usage default ---
        if (!isset($result['usage']) || $result['usage'] === '') {
            $result['usage'] = 'both';
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Private normalisation helpers
    // -------------------------------------------------------------------------

    /**
     * Strip '%' suffix and format VAT rate to 2 decimal places.
     *
     * Examples:
     *   '19%'   → '19.00'
     *   '19.5'  → '19.50'
     *   '9,00'  → '9.00'
     *   '0'     → '0.00'
     */
    private function normaliseVatRate(string $raw): string
    {
        $clean = trim($raw);

        // Strip trailing percent sign.
        $clean = rtrim($clean, '%');
        $clean = trim($clean);

        // Replace European comma decimal separator.
        $clean = str_replace(',', '.', $clean);

        // Remove any remaining non-numeric characters except '.'.
        $clean = preg_replace('/[^0-9.]/', '', $clean) ?? '';

        if ($clean === '' || !is_numeric($clean)) {
            return '0.00';
        }

        return number_format((float) $clean, 2, '.', '');
    }

    /**
     * Detect a boolean truthy value from Romanian and English string inputs.
     *
     * Truthy: 'da', 'yes', '1', 'true', 'serviciu', 'service', 'adevarat'
     * Everything else is falsy.
     */
    private function normaliseBoolean(string $raw): bool
    {
        $lower = strtolower(trim($raw));

        return in_array($lower, ['da', 'yes', '1', 'true', 'serviciu', 'service', 'adevarat'], true);
    }

    /**
     * Map common Romanian unit-of-measure abbreviations to canonical values.
     *
     * Unknown values are passed through as-is (lower-cased and trimmed) so
     * that the user's data is preserved even when we have no mapping.
     */
    private function normaliseUnitOfMeasure(string $raw): string
    {
        $clean = trim($raw);

        $map = [
            // Bucată (piece)
            'buc'        => 'buc',
            'bucata'     => 'buc',
            'bucată'     => 'buc',
            'bucati'     => 'buc',
            'bucăți'     => 'buc',
            'buc.'       => 'buc',
            'pcs'        => 'buc',
            'pce'        => 'buc',
            'piece'      => 'buc',
            'pieces'     => 'buc',
            'ea'         => 'buc',
            // Kilogram
            'kg'         => 'kg',
            'kilogram'   => 'kg',
            'kilograme'  => 'kg',
            // Gram
            'g'          => 'g',
            'gram'       => 'g',
            'grame'      => 'g',
            // Litru (litre)
            'l'          => 'l',
            'litru'      => 'l',
            'litri'      => 'l',
            'ltr'        => 'l',
            // Metru (metre)
            'm'          => 'm',
            'metru'      => 'm',
            'metri'      => 'm',
            // Metru pătrat (square metre)
            'm2'         => 'm2',
            'mp'         => 'm2',
            'metru patrat'  => 'm2',
            'metru pătrat'  => 'm2',
            // Metru cub (cubic metre)
            'm3'         => 'm3',
            'mc'         => 'm3',
            'metru cub'  => 'm3',
            // Oră (hour)
            'ora'        => 'ora',
            'oră'        => 'ora',
            'ore'        => 'ora',
            'h'          => 'ora',
            'hr'         => 'ora',
            'hour'       => 'ora',
            'hours'      => 'ora',
            // Zi (day)
            'zi'         => 'zi',
            'zile'       => 'zi',
            'day'        => 'zi',
            'days'       => 'zi',
            // Set / kit
            'set'        => 'set',
            'kit'        => 'set',
            // Pereche (pair)
            'per'        => 'per',
            'pereche'    => 'per',
            'pair'       => 'per',
            // Tonă (tonne)
            't'          => 't',
            'tona'       => 't',
            'tonă'       => 't',
            'tone'       => 't',
            'tonne'      => 't',
        ];

        $lower = strtolower($clean);

        return $map[$lower] ?? $clean;
    }

    /**
     * Normalise a price string to a plain decimal number string.
     *
     * Handles:
     *  - Currency symbols (RON, EUR, $, €, lei): stripped
     *  - European thousands separator with comma decimal: '1.234,56' → '1234.56'
     *  - Simple comma decimal: '19,99' → '19.99'
     *  - Leading/trailing whitespace
     *
     * Returns '0.00' for values that cannot be parsed.
     */
    private function normalisePrice(string $raw): string
    {
        $clean = trim($raw);

        // Strip currency symbols and codes.
        $clean = preg_replace('/\b(RON|EUR|USD|GBP|lei)\b/i', '', $clean) ?? '';
        $clean = str_replace(['$', '€', '£'], '', $clean);
        $clean = trim($clean);

        // Detect European format: dot as thousands separator, comma as decimal.
        // Pattern: digits, optional (dot + 3 digits)*, comma + 1-2 digits at end.
        if (preg_match('/^\d{1,3}(?:\.\d{3})*,\d{1,2}$/', $clean)) {
            $clean = str_replace('.', '', $clean);  // remove thousands dots
            $clean = str_replace(',', '.', $clean); // comma → decimal dot
        } else {
            // Simple comma-as-decimal: '19,99' → '19.99' (no thousands dot present).
            $clean = str_replace(',', '.', $clean);
        }

        // Remove any character that is not a digit or dot.
        $clean = preg_replace('/[^0-9.]/', '', $clean) ?? '';

        if ($clean === '' || !is_numeric($clean)) {
            return '0.00';
        }

        return number_format((float) $clean, 2, '.', '');
    }

    // -------------------------------------------------------------------------
    // Protected utility for concrete mappers
    // -------------------------------------------------------------------------

    /**
     * Return the ratio of expected anchor columns found in the given headers.
     *
     * A return value of 1.0 means all anchors were found; 0.0 means none.
     *
     * @param string[] $anchors
     * @param string[] $headers
     */
    protected function ratioFound(array $anchors, array $headers): float
    {
        if ($anchors === []) {
            return 0.0;
        }

        $found = 0;
        foreach ($anchors as $column) {
            if (in_array($column, $headers, true)) {
                $found++;
            }
        }

        return round($found / count($anchors), 2);
    }
}
