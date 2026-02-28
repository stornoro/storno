<?php

namespace App\Service\Import\Mapper;

/**
 * Column mapper for recurring invoice exports produced by FGO.
 *
 * FGO exports recurring invoices with columns like "Nr. recurenta", "Client",
 * "CUI client", "Tip frecventa", etc. Each row represents one recurring invoice
 * with a single line item.
 */
class FgoRecurringInvoiceMapper extends AbstractRecurringInvoiceMapper
{
    public function getSource(): string
    {
        return 'fgo';
    }

    /**
     * @return array<string, string>
     */
    public function getDefaultMapping(): array
    {
        return [
            'Nr. recurenta'               => 'reference',
            'Client'                      => 'clientName',
            'CUI client'                  => 'clientCif',
            'Moneda'                      => 'currency',
            'Seria'                       => 'seriesName',
            'Descriere'                   => 'description',
            'Tip frecventa'               => 'frequency',
            'Status'                      => 'isActive',
            'Urmatoarea emitere'          => 'nextIssuanceDate',
            'Zi facturare'                => 'frequencyDay',
            'Zi scadenta'                 => 'dueDateFixedDay',
            'Nr. zile scadenta'           => 'dueDateDays',
            'Are penalizare'              => 'penaltyEnabled',
            'Procent penalitati'          => 'penaltyPercentPerDay',
            'Nr. zile gratie penalitati'  => 'penaltyGraceDays',
            'Generare email'              => 'autoEmailEnabled',
            'Ora trimitere email'         => 'autoEmailTime',
            'Nr zile email de la emitere' => 'autoEmailDayOffset',
            'Nume articol'                => 'lineDescription',
            'Cod articol'                 => 'lineProductCode',
            'UM'                          => 'lineUnitOfMeasure',
            'TVA %'                       => 'lineVatRate',
            'Cantitate'                   => 'lineQuantity',
            'Pret unitar'                 => 'lineUnitPrice',
            'Total'                       => 'lineTotal',
            'Regula calcul pret'          => 'linePriceRule',
            'Valuta referinta'            => 'lineReferenceCurrency',
        ];
    }

    /**
     * @param string[] $headers
     */
    public function detectConfidence(array $headers): float
    {
        $anchors = ['Nr. recurenta', 'CUI client', 'Tip frecventa', 'Urmatoarea emitere'];

        return $this->ratioFound($anchors, $headers);
    }

    /**
     * Extends base mapRow to handle FGO-specific normalizations.
     *
     * @param array<string, string> $row
     * @param array<string, string> $columnMapping
     * @return array<string, mixed>
     */
    public function mapRow(array $row, array $columnMapping): array
    {
        $result = parent::mapRow($row, $columnMapping);

        // FGO uses "Descriere" as a general description — use it as notes
        // and also use "Descriere articol" column for line description if present
        if (!empty($result['description'])) {
            $result['notes'] = $result['description'];
        }

        // Normalize FGO price rule values
        if (!empty($result['lines'])) {
            foreach ($result['lines'] as &$line) {
                if (isset($line['priceRule'])) {
                    $line['priceRule'] = $this->normalizePriceRule($line['priceRule']);
                }
            }
            unset($line);
        }

        return $result;
    }

    private function normalizePriceRule(string $value): string
    {
        $lower = mb_strtolower(trim($value));

        $map = [
            'fix'        => 'fixed',
            'fixed'      => 'fixed',
            'curs bnr'   => 'bnr_rate',
            'bnr_rate'   => 'bnr_rate',
            'markup'     => 'markup',
            'adaos'      => 'markup',
        ];

        return $map[$lower] ?? 'fixed';
    }
}
