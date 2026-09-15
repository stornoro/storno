<?php

namespace App\Service\Import\Mapper;

/**
 * Base mapper for web-shop order exports (WooCommerce, PrestaShop): one row
 * per order, turned into one issued invoice to the customer. The customer
 * becomes a client (CUI / CNP / name from the billing columns; orders without
 * a name are pooled under "Persoană fizică"). Orders whose status is not
 * paid / completed are skipped unless the `includeAll` option is set.
 *
 * Header aliases cover the admin export of each platform and the usual
 * export plugins; the mapping step fixes anything renamed.
 */
abstract class AbstractShopOrderMapper extends AbstractInvoiceMapper implements HeaderAwareMappingInterface, TemplateAwareMapperInterface
{
    public function getImportType(): string
    {
        return 'invoices_issued';
    }

    /**
     * @return array<string, string[]>
     */
    abstract protected function getHeaderAliases(): array;

    /**
     * Order statuses that mean the order was paid / completed.
     *
     * @return string[] normalised (lowercase, ascii)
     */
    abstract protected function getPaidStatuses(): array;

    /**
     * Invoice-number prefix for the order reference ("WC-1234").
     */
    abstract protected function getNumberPrefix(): string;

    public function getRequiredFields(): array
    {
        return ['number', 'issueDate', 'total'];
    }

    public function getTargetFields(): array
    {
        return [
            'number'           => 'Nr. comandă',
            'orderReference'   => 'Referință comandă',
            'issueDate'        => 'Data comenzii',
            'status'           => 'Status comandă',
            'receiverName'     => 'Client (nume)',
            'receiverCompany'  => 'Client (firmă)',
            'receiverCif'      => 'Client (CUI)',
            'receiverCnp'      => 'Client (CNP)',
            'clientEmail'      => 'Email client',
            'clientPhone'      => 'Telefon client',
            'clientAddress'    => 'Adresă facturare',
            'clientCity'       => 'Oraș facturare',
            'clientCounty'     => 'Județ facturare',
            'clientPostalCode' => 'Cod poștal facturare',
            'clientCountry'    => 'Țară facturare',
            'lineItems'        => 'Produse comandate',
            'subtotal'         => 'Valoare fără TVA',
            'vatTotal'         => 'TVA',
            'shippingTotal'    => 'Transport',
            'discount'         => 'Discount',
            'total'            => 'Total comandă',
            'currency'         => 'Monedă',
            'paymentMethod'    => 'Metodă de plată',
            'notes'            => 'Observații',
        ];
    }

    public function getDefaultMapping(): array
    {
        $mapping = [];
        foreach ($this->getHeaderAliases() as $target => $aliases) {
            $mapping[$aliases[0]] = $target;
        }

        return $mapping;
    }

    public function suggestMapping(array $headers): array
    {
        $normalizedAliases = [];
        foreach ($this->getHeaderAliases() as $target => $aliases) {
            foreach ($aliases as $alias) {
                $normalizedAliases[AbstractPlatformStatementMapper::normalizeHeader($alias)] = $target;
            }
        }

        $mapping = [];
        $used = [];
        foreach ($headers as $header) {
            $target = $normalizedAliases[AbstractPlatformStatementMapper::normalizeHeader($header)] ?? null;
            if ($target !== null && !isset($used[$target])) {
                $mapping[$header] = $target;
                $used[$target] = true;
            }
        }

        return $mapping;
    }

    public function detectConfidence(array $headers): float
    {
        $found = array_values($this->suggestMapping($headers));
        $anchors = ['number', 'issueDate', 'status', 'total'];

        return round(count(array_intersect($anchors, $found)) / count($anchors), 2);
    }

    public function mapRow(array $row, array $columnMapping): array
    {
        $result = [];
        foreach ($columnMapping as $sourceCol => $targetField) {
            if ($targetField === '' || $targetField === null) {
                continue;
            }
            $value = $row[$sourceCol] ?? null;
            if ($value === null || trim((string) $value) === '') {
                continue;
            }
            $result[$targetField] = trim((string) $value);
        }

        $result = $this->combineNameColumns($result, $row);

        foreach (['subtotal', 'vatTotal', 'shippingTotal', 'discount', 'total'] as $field) {
            if (isset($result[$field])) {
                $result[$field] = AbstractPlatformStatementMapper::normalizeAmount($result[$field]);
            }
        }

        if (isset($result['issueDate'])) {
            $result['issueDate'] = AbstractPlatformStatementMapper::normalizeDate($result['issueDate']) ?? $result['issueDate'];
        }

        $orderId = $result['number'] ?? '';
        $reference = $result['orderReference'] ?? $orderId;
        if ($orderId !== '') {
            $result['orderNumber'] = $reference;
            $result['number'] = $this->getNumberPrefix() . '-' . preg_replace('/\s+/', '', $reference !== '' ? $reference : $orderId);
        }

        $result['direction'] = 'issued';
        $result['currency'] = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $result['currency'] ?? '') ?: 'RON', 0, 3));
        $result['_createClient'] = true;
        $result['_statusPaid'] = $this->isPaidStatus($result['status'] ?? '');

        if (!empty($result['receiverCompany']) && empty($result['receiverCif'])) {
            // A company name without a CUI: keep the person's name and note the company
            $result['receiverName'] = $result['receiverCompany'] . (!empty($result['receiverName']) ? ' — ' . $result['receiverName'] : '');
        } elseif (!empty($result['receiverCompany'])) {
            $result['receiverName'] = $result['receiverCompany'];
        }
        if (empty($result['receiverName'])) {
            $result['receiverName'] = 'Persoană fizică';
        }
        if (!empty($result['receiverCif'])) {
            $result['receiverCif'] = strtoupper(preg_replace('/\s+/', '', $result['receiverCif']));
        }

        $this->buildTotalsAndLines($result, $reference !== '' ? $reference : $orderId);

        return $result;
    }

    /**
     * Totals: net / VAT from the export when both are known, otherwise the
     * gross total is split later with the company's default VAT rate.
     */
    private function buildTotalsAndLines(array &$result, string $reference): void
    {
        $gross = (float) ($result['total'] ?? 0);
        $vat = isset($result['vatTotal']) ? (float) $result['vatTotal'] : null;
        $knownVat = $vat !== null;
        $net = $knownVat ? round($gross - $vat, 2) : $gross;
        $rate = $knownVat && $net > 0 ? round($vat / $net * 100, 2) : 0.0;

        $lines = $this->parseLineItems($result['lineItems'] ?? '', $gross);
        if ($lines === []) {
            $lines = [['description' => 'Comandă #' . $reference, 'quantity' => '1', 'lineGross' => $gross]];
        }

        $result['lines'] = [];
        $sumGross = array_sum(array_column($lines, 'lineGross')) ?: $gross;
        foreach ($lines as $line) {
            $lineGross = (float) $line['lineGross'];
            $share = $sumGross > 0 ? $lineGross / $sumGross : 0;
            $lineNet = $knownVat ? round($net * $share, 2) : $lineGross;
            $lineVat = $knownVat ? round($lineGross - $lineNet, 2) : 0.0;
            $qty = max((float) ($line['quantity'] ?? 1), 0.0001);
            $result['lines'][] = [
                'description'   => $line['description'],
                'quantity'      => number_format($qty, 4, '.', ''),
                'unitOfMeasure' => 'buc',
                'unitPrice'     => number_format($lineNet / $qty, 2, '.', ''),
                'vatRate'       => number_format($rate, 2, '.', ''),
                'vatAmount'     => number_format($lineVat, 2, '.', ''),
                'lineTotal'     => number_format($lineNet, 2, '.', ''),
                'vatCategoryCode' => $rate > 0 ? 'S' : 'O',
            ];
        }

        $result['subtotal'] = number_format($net, 2, '.', '');
        $result['vatTotal'] = number_format($vat ?? 0.0, 2, '.', '');
        $result['total'] = number_format($gross, 2, '.', '');
        if (!$knownVat) {
            $result['_vatIncludedUnknownRate'] = true;
        }
        unset($result['lineItems'], $result['receiverCompany'], $result['shippingTotal']);
    }

    /**
     * "Product A x 2 = 100.00 | Product B x 1" → [{description, quantity, lineGross}].
     * Without amounts the order total is spread by quantity.
     *
     * @return array<int, array{description: string, quantity: string, lineGross: float}>
     */
    protected function parseLineItems(string $cell, float $orderGross): array
    {
        $cell = trim($cell);
        if ($cell === '') {
            return [];
        }

        $parts = preg_split('/\s*(?:\||;|\n|\r\n)\s*/', $cell) ?: [];
        $items = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $qty = 1.0;
            $amount = null;
            $name = $part;
            if (preg_match('/^(.*?)\s*[x×]\s*(\d+(?:[.,]\d+)?)\s*(?:[=@:]\s*([0-9.,]+))?\s*$/u', $part, $m)) {
                $name = trim($m[1]);
                $qty = (float) str_replace(',', '.', $m[2]);
                if (isset($m[3]) && $m[3] !== '') {
                    $amount = (float) AbstractPlatformStatementMapper::normalizeAmount($m[3]);
                }
            } elseif (preg_match('/^(\d+(?:[.,]\d+)?)\s*[x×]\s*(.*?)(?:\s*[=@:]\s*([0-9.,]+))?\s*$/u', $part, $m)) {
                $qty = (float) str_replace(',', '.', $m[1]);
                $name = trim($m[2]);
                if (isset($m[3]) && $m[3] !== '') {
                    $amount = (float) AbstractPlatformStatementMapper::normalizeAmount($m[3]);
                }
            }
            $items[] = ['description' => $name !== '' ? $name : 'Produs', 'quantity' => (string) $qty, 'lineGross' => $amount];
        }

        if ($items === []) {
            return [];
        }

        $known = array_filter($items, fn ($i) => $i['lineGross'] !== null);
        if (count($known) === count($items) && array_sum(array_column($items, 'lineGross')) > 0) {
            return $items;
        }

        // Spread the order total by quantity
        $totalQty = array_sum(array_map(fn ($i) => (float) $i['quantity'], $items)) ?: 1;
        $left = $orderGross;
        foreach ($items as $k => $item) {
            $items[$k]['lineGross'] = $k === array_key_last($items) ? round($left, 2) : round($orderGross * (float) $item['quantity'] / $totalQty, 2);
            $left -= $items[$k]['lineGross'];
        }

        return $items;
    }

    /**
     * Exports that split the customer's name in first / last name columns.
     */
    protected function combineNameColumns(array $result, array $row): array
    {
        return $result;
    }

    protected function isPaidStatus(string $status): bool
    {
        $s = AbstractPlatformStatementMapper::normalizeHeader($status);
        $s = trim(preg_replace('/^wc /', '', $s) ?? $s);
        if ($s === '') {
            return true; // no status column: nothing to filter on
        }
        foreach ($this->getPaidStatuses() as $paid) {
            if ($s === $paid || str_starts_with($s, $paid)) {
                return true;
            }
        }

        return false;
    }
}
