<?php

namespace App\Service\Import\Mapper;

/**
 * Collections of issued invoices from a spreadsheet: one row per payment, naming the invoice
 * it pays, the date and the amount. Several rows for one invoice are its partial payments.
 *
 * One instance exists per source: the orchestrator registers it for every program whose
 * invoices can be imported.
 */
class PaymentMapper implements ColumnMapperInterface, HeaderAwareMappingInterface
{
    public const TYPE = 'payments';

    public function __construct(private readonly string $source = 'generic')
    {
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getImportType(): string
    {
        return self::TYPE;
    }

    public function getRequiredFields(): array
    {
        return ['invoiceNumber', 'amount'];
    }

    /**
     * @return array<string, string>
     */
    public function getTargetFields(): array
    {
        return [
            'series'        => 'Serie factura',
            'invoiceNumber' => 'Nr. factura',
            'paymentDate'   => 'Data incasarii',
            'amount'        => 'Suma incasata',
            'paymentMethod' => 'Metoda plata',
            'reference'     => 'Document plata (nr. chitanta / OP)',
            'notes'         => 'Observatii',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getDefaultMapping(): array
    {
        return array_flip($this->getTargetFields());
    }

    /** Usual header names per field, compared without case, diacritics or punctuation. */
    private const ALIASES = [
        'series'        => ['serie', 'seria', 'serie factura', 'serie document'],
        'invoiceNumber' => ['factura', 'nr factura', 'numar factura', 'nr document', 'numar document', 'document', 'numar', 'nr', 'serie si numar', 'factura achitata', 'invoice', 'invoice number'],
        'paymentDate'   => ['data', 'data incasare', 'data incasarii', 'data plata', 'data platii', 'data chitanta', 'data document plata', 'incasat la', 'date', 'payment date'],
        'amount'        => ['suma', 'suma incasata', 'valoare', 'valoare incasata', 'incasat', 'suma platita', 'suma achitata', 'achitat', 'total', 'amount', 'paid'],
        'paymentMethod' => ['metoda plata', 'metoda de plata', 'mod plata', 'mod de plata', 'modalitate plata', 'tip plata', 'tip incasare', 'payment method'],
        'reference'     => ['referinta', 'chitanta', 'nr chitanta', 'numar chitanta', 'nr op', 'op', 'document plata', 'nr document plata', 'explicatie', 'reference'],
        'notes'         => ['observatii', 'mentiuni', 'note', 'notes', 'detalii'],
    ];

    /**
     * @param string[] $headers
     * @return array<string, string>
     */
    public function suggestMapping(array $headers): array
    {
        $default = $this->getDefaultMapping();
        $mapping = [];
        foreach ($headers as $header) {
            $header = (string) $header;
            $target = $default[$header] ?? null;
            if ($target === null) {
                $normalized = self::normalize($header);
                foreach (self::ALIASES as $field => $aliases) {
                    if (in_array($normalized, $aliases, true)) {
                        $target = $field;
                        break;
                    }
                }
            }
            // first column wins when two headers look like the same field
            if ($target !== null && !in_array($target, $mapping, true)) {
                $mapping[$header] = $target;
            }
        }

        return $mapping;
    }

    /**
     * @param string[] $headers
     */
    public function detectConfidence(array $headers): float
    {
        $targets = array_values($this->suggestMapping($headers));
        $found = 0;
        foreach (['invoiceNumber', 'amount', 'paymentDate'] as $field) {
            $found += in_array($field, $targets, true) ? 1 : 0;
        }

        return round($found / 3, 2);
    }

    /**
     * @param array<string, string> $row
     * @param array<string, string> $columnMapping
     * @return array<string, mixed>
     */
    public function mapRow(array $row, array $columnMapping): array
    {
        $v = [];
        foreach ($columnMapping as $sourceCol => $target) {
            if (!is_string($target) || $target === '') {
                continue;
            }
            $value = trim((string) ($row[$sourceCol] ?? ''));
            if ($value !== '' && !isset($v[$target])) {
                $v[$target] = $value;
            }
        }

        $number = $v['invoiceNumber'] ?? '';
        if ($number !== '' && preg_match('/^\d+\.0+$/', $number)) {
            $number = (string) (int) $number; // spreadsheet number cell: "125.0"
        }
        $series = $v['series'] ?? '';
        if ($number !== '' && $series !== '' && stripos($number, $series) !== 0) {
            $number = $series . $number;
        }

        $result = ['invoiceNumber' => $number];

        if (isset($v['amount'])) {
            $amount = self::amount($v['amount']);
            // an unreadable amount is kept so that the row is reported, not silently dropped
            $result['amount'] = $amount !== null ? number_format($amount, 2, '.', '') : $v['amount'];
        }
        if (isset($v['paymentDate'])) {
            $result['paymentDate'] = GenericInvoiceMapper::normalizeDate($v['paymentDate']) ?? $v['paymentDate'];
        }
        if (isset($v['paymentMethod'])) {
            $text = self::normalize($v['paymentMethod']);
            $result['paymentMethod'] = match (true) {
                (bool) preg_match('/numerar|cash|chitanta/', $text) => 'cash',
                (bool) preg_match('/card|pos\b/', $text) => 'card',
                default => 'bank_transfer',
            };
        }
        foreach (['reference', 'notes'] as $field) {
            if (isset($v[$field])) {
                $result[$field] = mb_substr($v[$field], 0, 500);
            }
        }

        return $result;
    }

    private static function normalize(string $header): string
    {
        $ascii = strtr(mb_strtolower(trim($header)), [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
        ]);
        $ascii = preg_replace('/\([^)]*\)/', ' ', $ascii) ?? $ascii;
        $ascii = preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? '';

        return trim(preg_replace('/\s+/', ' ', $ascii) ?? '');
    }

    /** "1.234,56", "1,234.56", "1234.56 lei" → 1234.56; null when the cell holds no number. */
    private static function amount(string $raw): ?float
    {
        $clean = preg_replace('/[^\d,.\-]/u', '', $raw) ?? '';
        if ($clean === '' || $clean === '-') {
            return null;
        }
        if (str_contains($clean, ',') && str_contains($clean, '.')) {
            // the last separator is the decimal one
            $clean = strrpos($clean, ',') > strrpos($clean, '.')
                ? str_replace(',', '.', str_replace('.', '', $clean))
                : str_replace(',', '', $clean);
        } else {
            $clean = str_replace(',', '.', $clean);
        }

        return is_numeric($clean) ? (float) $clean : null;
    }
}
