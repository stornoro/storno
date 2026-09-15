<?php

namespace App\Service\Import\Mapper;

use App\Service\Import\Parser\A4200XmlParser;

/**
 * Column mapper for the rows the A4200XmlParser produces from a cash
 * register's XML export (import type `receipts`, source `cash_register`).
 * The columns are fixed, so the mapping is the identity; it is still shown
 * in the wizard so the user sees what each cell becomes.
 */
class CashRegisterA4200Mapper implements ColumnMapperInterface
{
    public const SOURCE = 'cash_register';
    public const IMPORT_TYPE = 'receipts';

    private const MAPPING = [
        A4200XmlParser::COL_TYPE          => 'type',
        A4200XmlParser::COL_FISCAL_ID     => 'fiscalId',
        A4200XmlParser::COL_SERIAL        => 'deviceSerial',
        A4200XmlParser::COL_DATE          => 'issueDate',
        A4200XmlParser::COL_TIME          => 'time',
        A4200XmlParser::COL_Z_NUMBER      => 'zReportNumber',
        A4200XmlParser::COL_NUMBER        => 'receiptNumber',
        A4200XmlParser::COL_TOTAL         => 'total',
        A4200XmlParser::COL_VAT_TOTAL     => 'vatTotal',
        A4200XmlParser::COL_VAT_BREAKDOWN => 'vatBreakdown',
        A4200XmlParser::COL_PAYMENTS      => 'payments',
        A4200XmlParser::COL_CUSTOMER_CIF  => 'customerCif',
        A4200XmlParser::COL_LINES         => 'lines',
        A4200XmlParser::COL_CURRENCY      => 'currency',
        A4200XmlParser::COL_RECEIPT_COUNT => 'receiptCount',
    ];

    public function getSource(): string
    {
        return self::SOURCE;
    }

    public function getImportType(): string
    {
        return self::IMPORT_TYPE;
    }

    public function getDefaultMapping(): array
    {
        return self::MAPPING;
    }

    public function getRequiredFields(): array
    {
        return ['type', 'issueDate', 'total'];
    }

    public function getTargetFields(): array
    {
        return [
            'type'          => 'Tip rând (bon / Z)',
            'fiscalId'      => 'Identificator fiscal bon',
            'deviceSerial'  => 'Serie casă de marcat',
            'issueDate'     => 'Data',
            'time'          => 'Ora',
            'zReportNumber' => 'Nr. raport Z',
            'receiptNumber' => 'Nr. bon',
            'total'         => 'Total bon',
            'vatTotal'      => 'Total TVA',
            'vatBreakdown'  => 'Defalcare TVA (cotă:TVA)',
            'payments'      => 'Plăți (tip:sumă)',
            'customerCif'   => 'CUI client',
            'lines'         => 'Linii produse',
            'currency'      => 'Monedă',
            'receiptCount'  => 'Bonuri declarate (raport Z)',
        ];
    }

    public function mapRow(array $row, array $columnMapping): array
    {
        $result = [];
        foreach ($columnMapping as $sourceCol => $targetField) {
            if ($targetField === '' || $targetField === null) {
                continue;
            }
            $value = $row[$sourceCol] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $result[$targetField] = trim((string) $value);
        }

        $result['type'] = strtoupper($result['type'] ?? 'BON') === 'Z' ? 'Z' : 'bon';

        return $result;
    }

    public function detectConfidence(array $headers): float
    {
        return in_array(A4200XmlParser::COL_FISCAL_ID, $headers, true) && in_array(A4200XmlParser::COL_VAT_BREAKDOWN, $headers, true) ? 1.0 : 0.0;
    }
}
