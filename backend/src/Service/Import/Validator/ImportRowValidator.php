<?php

namespace App\Service\Import\Validator;

use App\Service\Import\Mapper\ColumnMapperInterface;

class ImportRowValidator
{
    /**
     * Validate a mapped row against the mapper's required fields.
     *
     * @param array<string, mixed>  $mappedData
     * @param ColumnMapperInterface $mapper
     * @return array<string, string> fieldName => error message (empty array if valid)
     */
    public function validate(array $mappedData, ColumnMapperInterface $mapper): array
    {
        $errors = [];
        $targetFields = $mapper->getTargetFields();

        // Check all required fields are present and non-empty
        foreach ($mapper->getRequiredFields() as $field) {
            if (empty($mappedData[$field])) {
                $label = $targetFields[$field] ?? $field;
                $errors[$field] = sprintf('Câmpul "%s" este obligatoriu.', $label);
            }
        }

        // Validate email format if present
        if (!empty($mappedData['email']) && !filter_var($mappedData['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Adresa de email nu este validă.';
        }

        // Validate CUI/CIF format if present (Romanian fiscal code: digits only, optionally prefixed with RO)
        if (!empty($mappedData['cui'])) {
            $cui = preg_replace('/^RO/i', '', trim($mappedData['cui']));
            if (!preg_match('/^\d{1,10}$/', $cui)) {
                $errors['cui'] = 'CUI/CIF invalid.';
            }
        }

        // Validate CNP format if present (Romanian personal ID: exactly 13 digits)
        if (!empty($mappedData['cnp'])) {
            $cnp = preg_replace('/\s+/', '', $mappedData['cnp']);
            if (!preg_match('/^\d{13}$/', $cnp)) {
                $errors['cnp'] = 'CNP invalid (trebuie să aibă exact 13 cifre).';
            }
        }

        // Validate country code if present (ISO 3166-1 alpha-2: exactly 2 letters)
        if (!empty($mappedData['country'])) {
            if (!preg_match('/^[A-Za-z]{2}$/', trim($mappedData['country']))) {
                $errors['country'] = 'Codul de țară trebuie să aibă 2 litere (ex: RO, DE).';
            }
        }

        // Validate invoice number is not blank (invoices only)
        if (array_key_exists('number', $mappedData) && empty($mappedData['number'])) {
            $errors['number'] = 'Numărul facturii este obligatoriu.';
        }

        // Validate numeric fields are actually numeric
        foreach (['defaultPrice', 'vatRate', 'quantity', 'unitPrice', 'subtotal', 'vatTotal', 'total'] as $numericField) {
            if (isset($mappedData[$numericField]) && $mappedData[$numericField] !== '' && !is_numeric($mappedData[$numericField])) {
                $label = $targetFields[$numericField] ?? $numericField;
                $errors[$numericField] = sprintf('Câmpul "%s" trebuie să fie numeric.', $label);
            }
        }

        // Validate date fields if present
        foreach (['issueDate', 'dueDate'] as $dateField) {
            if (!empty($mappedData[$dateField])) {
                $parsed = \DateTime::createFromFormat('Y-m-d', $mappedData[$dateField])
                    ?: \DateTime::createFromFormat('d.m.Y', $mappedData[$dateField])
                    ?: \DateTime::createFromFormat('d/m/Y', $mappedData[$dateField]);

                if ($parsed === false) {
                    $label = $targetFields[$dateField] ?? $dateField;
                    $errors[$dateField] = sprintf('Câmpul "%s" are o dată invalidă (format așteptat: YYYY-MM-DD).', $label);
                }
            }
        }

        return $errors;
    }
}
