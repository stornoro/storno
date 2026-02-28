<?php

namespace App\Service\Import\Mapper;

abstract class AbstractRecurringInvoiceMapper implements ColumnMapperInterface
{
    public function getImportType(): string
    {
        return 'recurring_invoices';
    }

    public function getRequiredFields(): array
    {
        return ['clientName'];
    }

    /**
     * @return array<string, string>
     */
    public function getTargetFields(): array
    {
        return [
            'reference'            => 'Referinta',
            'clientName'           => 'Client',
            'clientCif'            => 'CUI client',
            'currency'             => 'Moneda',
            'seriesName'           => 'Serie',
            'description'          => 'Descriere',
            'frequency'            => 'Frecventa',
            'isActive'             => 'Status',
            'nextIssuanceDate'     => 'Urmatoarea emitere',
            'frequencyDay'         => 'Zi facturare',
            'dueDateDays'          => 'Nr. zile scadenta',
            'dueDateFixedDay'      => 'Zi scadenta',
            'penaltyEnabled'       => 'Are penalizare',
            'penaltyPercentPerDay' => 'Procent penalitati',
            'penaltyGraceDays'     => 'Nr. zile gratie penalitati',
            'autoEmailEnabled'     => 'Generare email',
            'autoEmailTime'        => 'Ora trimitere email',
            'autoEmailDayOffset'   => 'Nr zile email de la emitere',
            // Line fields
            'lineDescription'       => 'Nume articol',
            'lineProductCode'       => 'Cod articol',
            'lineUnitOfMeasure'     => 'UM',
            'lineVatRate'           => 'TVA %',
            'lineQuantity'          => 'Cantitate',
            'lineUnitPrice'         => 'Pret unitar',
            'lineTotal'             => 'Total linie',
            'linePriceRule'         => 'Regula calcul pret',
            'lineReferenceCurrency' => 'Valuta referinta',
        ];
    }

    /**
     * @param array<string, string> $row
     * @param array<string, string> $columnMapping
     * @return array<string, mixed>
     */
    public function mapRow(array $row, array $columnMapping): array
    {
        $result = [];
        $lineData = [];

        foreach ($columnMapping as $sourceCol => $targetField) {
            if ($targetField === '' || $targetField === null) {
                continue;
            }

            $value = $row[$sourceCol] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            if (str_starts_with($targetField, 'line')) {
                $lineField = lcfirst(substr($targetField, 4));
                $lineData[$lineField] = $value;
            } else {
                $result[$targetField] = $value;
            }
        }

        if (!empty($lineData)) {
            $result['lines'] = [$lineData];
        }

        // Normalize amounts
        foreach (($result['lines'] ?? []) as &$line) {
            foreach (['unitPrice', 'vatAmount', 'total', 'quantity'] as $f) {
                if (isset($line[$f])) {
                    $line[$f] = $this->normalizeAmount($line[$f]);
                }
            }
        }
        unset($line);

        // Normalize numeric fields
        foreach (['penaltyPercentPerDay', 'dueDateDays', 'dueDateFixedDay', 'frequencyDay', 'autoEmailDayOffset', 'penaltyGraceDays'] as $f) {
            if (isset($result[$f])) {
                $result[$f] = $this->normalizeAmount($result[$f]);
            }
        }

        // Normalize frequency
        if (isset($result['frequency'])) {
            $result['frequency'] = $this->normalizeFrequency($result['frequency']);
        }

        // Normalize boolean fields
        foreach (['isActive', 'penaltyEnabled', 'autoEmailEnabled'] as $f) {
            if (isset($result[$f])) {
                $result[$f] = $this->normalizeBool($result[$f]);
            }
        }

        // Normalize date fields
        foreach (['nextIssuanceDate'] as $f) {
            if (isset($result[$f])) {
                $result[$f] = $this->normalizeDate($result[$f]);
            }
        }

        return $result;
    }

    protected function normalizeAmount(string $value): string
    {
        $value = str_replace([' ', "\xC2\xA0"], '', $value);

        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = str_replace('.', '', $value);
        }

        return str_replace(',', '.', $value);
    }

    protected function normalizeFrequency(string $value): string
    {
        $map = [
            'lunar'          => 'monthly',
            'lunara'         => 'monthly',
            'monthly'        => 'monthly',
            'saptamanal'     => 'weekly',
            'saptamanala'    => 'weekly',
            'weekly'         => 'weekly',
            'bilunar'        => 'bimonthly',
            'bilunara'       => 'bimonthly',
            'bimonthly'      => 'bimonthly',
            'trimestrial'    => 'quarterly',
            'trimestriala'   => 'quarterly',
            'quarterly'      => 'quarterly',
            'semestrial'     => 'semi_annually',
            'semestriala'    => 'semi_annually',
            'semi_annually'  => 'semi_annually',
            'anual'          => 'yearly',
            'anuala'         => 'yearly',
            'yearly'         => 'yearly',
            'o singura data' => 'once',
            'once'           => 'once',
        ];

        return $map[mb_strtolower(trim($value))] ?? 'monthly';
    }

    protected function normalizeBool(string $value): bool
    {
        $lower = mb_strtolower(trim($value));
        return in_array($lower, ['da', 'yes', '1', 'true', 'activa', 'activ'], true);
    }

    protected function normalizeDate(string $value): ?string
    {
        // Try dd.mm.yyyy
        $parsed = \DateTime::createFromFormat('d.m.Y', trim($value));
        if ($parsed !== false) {
            return $parsed->format('Y-m-d');
        }

        // Try yyyy-mm-dd
        $parsed = \DateTime::createFromFormat('Y-m-d', trim($value));
        if ($parsed !== false) {
            return $parsed->format('Y-m-d');
        }

        // Try dd/mm/yyyy
        $parsed = \DateTime::createFromFormat('d/m/Y', trim($value));
        if ($parsed !== false) {
            return $parsed->format('Y-m-d');
        }

        return null;
    }

    /**
     * @param string[] $anchors
     * @param string[] $headers
     */
    protected function ratioFound(array $anchors, array $headers): float
    {
        if ($anchors === []) {
            return 0.0;
        }

        $normalizedHeaders = array_map('mb_strtolower', $headers);
        $found = 0;
        foreach ($anchors as $anchor) {
            if (in_array(mb_strtolower($anchor), $normalizedHeaders, true)) {
                $found++;
            }
        }

        return round($found / count($anchors), 2);
    }
}
