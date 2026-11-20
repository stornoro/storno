<?php

namespace App\Service\Import\Mapper;

/**
 * Invoice history from a spreadsheet: the invoice report / sales journal every invoicing
 * and accounting program can export, or a hand-made CSV / XLSX.
 *
 * Such reports have no fixed layout — the columns depend on the program, on the report
 * chosen and on the columns the user ticked — so the mapping is suggested from the headers
 * the file actually has, by their usual names. Two shapes are read:
 *
 *  - one row per invoice (number, date, client, value, VAT, total): the invoice gets a
 *    single line carrying its amounts;
 *  - one row per invoice line (product, quantity, price…): rows sharing an invoice number
 *    are gathered by the persister and the invoice totals are the sum of its lines.
 *
 * One instance exists per (source, direction): the orchestrator registers it for every
 * program that has a client import and no invoice mapper of its own.
 */
class GenericInvoiceMapper extends AbstractInvoiceMapper implements HeaderAwareMappingInterface
{
    public const TYPE_ISSUED = 'invoices_issued';
    public const TYPE_RECEIVED = 'invoices_received';

    public function __construct(
        private readonly string $source = 'generic',
        private readonly string $importType = self::TYPE_ISSUED,
    ) {
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getImportType(): string
    {
        return $this->importType;
    }

    protected function isIssued(): bool
    {
        return $this->importType !== self::TYPE_RECEIVED;
    }

    /**
     * @return array<string, string>
     */
    public function getTargetFields(): array
    {
        $partner = $this->isIssued()
            ? ['receiverName' => 'Client (nume)', 'receiverCif' => 'Client (CUI / CNP)', 'senderCif' => 'CUI emitent (firma ta)']
            : ['senderName' => 'Furnizor (nume)', 'senderCif' => 'Furnizor (CUI)', 'receiverCif' => 'CUI cumparator (firma ta)'];

        return [
            'series'             => 'Serie',
            'number'             => 'Nr. factura',
            'issueDate'          => 'Data emitere',
            'dueDate'            => 'Data scadenta',
        ] + $partner + [
            'subtotal'           => 'Valoare fara TVA',
            'vatTotal'           => 'TVA',
            'total'              => 'Total',
            'currency'           => 'Moneda',
            'exchangeRate'       => 'Curs valutar',
            'paidAmount'         => 'Suma incasata',
            'unpaidAmount'       => 'Rest de plata',
            'state'              => 'Stare (incasata / anulata)',
            'stornoOf'           => 'Factura stornata (numar)',
            'paymentMethod'      => 'Metoda plata',
            'notes'              => 'Observatii',
            'lineDescription'    => 'Produs / Descriere',
            'lineProductCode'    => 'Cod produs',
            'lineQuantity'       => 'Cantitate',
            'lineUnitOfMeasure'  => 'UM',
            'lineUnitPrice'      => 'Pret unitar (fara TVA)',
            'lineVatRate'        => 'Cota TVA',
            'lineVatAmount'      => 'TVA linie',
            'lineTotal'          => 'Valoare linie fara TVA',
            'lineTotalGross'     => 'Valoare linie cu TVA',
            'clientEmail'        => 'Email partener',
            'clientPhone'        => 'Telefon partener',
            'clientAddress'      => 'Adresa partener',
            'clientCity'         => 'Oras partener',
            'clientCounty'       => 'Judet partener',
            'clientCountry'      => 'Tara partener',
        ];
    }

    /**
     * The template's own headers (the labels above) map back to their fields.
     *
     * @return array<string, string>
     */
    public function getDefaultMapping(): array
    {
        return array_flip($this->getTargetFields());
    }

    /** Usual header names per field, compared without case, diacritics or punctuation. */
    private const ALIASES = [
        'series'       => ['facturaseria', 'serie', 'seria', 'serie factura', 'serie document', 'serie doc', 'series'],
        'number'       => ['facturanumar', 'numar', 'nr', 'numar factura', 'nr factura', 'factura', 'nr document', 'numar document', 'nr doc', 'document', 'serie si numar', 'serie numar', 'numar si serie', 'invoice', 'invoice number', 'invoice no', 'number'],
        'issueDate'    => ['facturadata', 'data', 'data factura', 'data facturii', 'data emitere', 'data emiterii', 'data document', 'data doc', 'emisa la', 'data emitere factura', 'date', 'issue date', 'invoice date'],
        'dueDate'      => ['facturascadenta', 'scadenta', 'data scadenta', 'data scadentei', 'termen', 'termen plata', 'termen de plata', 'due date'],
        'partnerName'  => ['clientnume', 'client', 'nume client', 'denumire client', 'cumparator', 'beneficiar', 'partener', 'denumire partener', 'nume partener', 'customer', 'customer name', 'client name', 'buyer'],
        'supplierName' => ['furnizornume', 'furnizor', 'nume furnizor', 'denumire furnizor', 'vanzator', 'partener', 'denumire partener', 'nume partener', 'supplier', 'supplier name', 'vendor', 'seller'],
        'partnerCif'   => ['cif', 'cui', 'cui cif', 'cif cui', 'cod fiscal', 'cif cnp', 'cui cnp', 'cnp cui', 'cnp cif', 'cif partener', 'cui partener', 'cod fiscal partener', 'vat number', 'tax id'],
        'clientCif'    => ['cif client', 'cui client', 'cod fiscal client', 'clientcif', 'cif cumparator', 'cui cumparator', 'cif beneficiar'],
        'supplierCif'  => ['cif furnizor', 'cui furnizor', 'cod fiscal furnizor', 'furnizorcif', 'cif vanzator', 'cui vanzator'],
        'subtotal'     => ['valoare', 'valoare fara tva', 'total fara tva', 'suma fara tva', 'baza', 'baza impozabila', 'baza de impozitare', 'valoare neta', 'net', 'subtotal', 'total net', 'amount', 'net amount'],
        'vatTotal'     => ['tva', 'valoare tva', 'total tva', 'suma tva', 'vat amount', 'vat', 'tax'],
        'total'        => ['total', 'total factura', 'valoare totala', 'total cu tva', 'valoare cu tva', 'total de plata', 'total general', 'suma', 'suma totala', 'total document', 'gross', 'total amount', 'grand total'],
        'currency'     => ['facturamoneda', 'moneda', 'valuta', 'currency', 'deviza'],
        'exchangeRate' => ['curs', 'curs valutar', 'curs bnr', 'curs de schimb', 'exchange rate'],
        'paidAmount'   => ['incasat', 'suma incasata', 'valoare incasata', 'achitat', 'suma achitata', 'platit', 'suma platita', 'paid', 'amount paid'],
        'unpaidAmount' => ['rest', 'rest de plata', 'rest de incasat', 'rest plata', 'sold', 'neincasat', 'de incasat', 'neachitat', 'sold factura', 'balance', 'amount due'],
        'state'        => ['stare', 'status', 'stare factura', 'status factura', 'stare incasare', 'status incasare', 'incasata', 'achitata', 'platita', 'state'],
        'stornoOf'     => ['factura stornata', 'storno la', 'storno la factura', 'storneaza factura', 'factura initiala', 'factura originala', 'factura de referinta', 'document stornat', 'referinta storno', 'storno of'],
        'paymentMethod' => ['metoda plata', 'metoda de plata', 'mod plata', 'mod de plata', 'modalitate plata', 'modalitate de plata', 'tip plata', 'payment method'],
        'notes'        => ['txtobservatii', 'facturainformatiisuplimentare', 'observatii', 'mentiuni', 'note', 'notes', 'observatii 1', 'detalii'],
        'lineDescription'   => ['produs', 'produs serviciu', 'denumire produs', 'nume produs', 'denumire articol', 'nume articol', 'articol', 'descriere', 'denumire produs serviciu', 'denumirea produselor sau a serviciilor', 'product', 'description', 'item'],
        'lineProductCode'   => ['codarticolfurnizor', 'cod produs', 'cod articol', 'sku', 'product code'],
        'lineQuantity'      => ['cantitate', 'cant', 'buc', 'quantity', 'qty'],
        'lineUnitOfMeasure' => ['um', 'u m', 'unitate masura', 'unitate de masura', 'unit'],
        'lineUnitPrice'     => ['pret', 'pret unitar', 'pret fara tva', 'pret unitar fara tva', 'pret unitar fara tva lei', 'unit price', 'price'],
        'lineVatRate'       => ['proctva', 'cotatva', 'cota tva', 'cota', 'tva %', 'procent tva', 'cota tva %', '% tva', 'vat rate', 'vat %'],
        'clientEmail'   => ['clientmail', 'email', 'e mail', 'email client', 'mail'],
        'clientPhone'   => ['clienttelefon', 'telefon', 'telefon client', 'tel', 'phone'],
        'clientAddress' => ['clientadresa', 'adresa', 'adresa client', 'sediu', 'address'],
        'clientCity'    => ['clientlocalitate', 'oras', 'localitate', 'localitatea', 'city'],
        'clientCounty'  => ['clientjudet', 'judet', 'judetul', 'county'],
        'clientCountry' => ['clienttara', 'tara', 'country', 'cod tara'],
    ];

    /** Headers that only make sense on a per-line report. */
    private const LINE_MARKERS = ['lineDescription', 'lineQuantity', 'lineUnitPrice'];

    /**
     * @param string[] $headers
     * @return array<string, string>
     */
    public function suggestMapping(array $headers): array
    {
        $guessed = [];
        foreach ($headers as $header) {
            $guessed[(string) $header] = $this->guess((string) $header);
        }

        // On a per-line report the amounts sit on the line, not on the invoice.
        $perLine = array_intersect(self::LINE_MARKERS, array_filter($guessed)) !== [];
        // (a file that names its line amounts itself, like the template, already says which is which)
        $lineLevel = ['subtotal' => 'lineTotal', 'vatTotal' => 'lineVatAmount', 'total' => 'lineTotalGross'];
        if ($perLine && array_intersect(array_values($lineLevel), array_filter($guessed)) === []) {
            foreach ($guessed as $header => $target) {
                if ($target !== null && isset($lineLevel[$target])) {
                    $guessed[$header] = $lineLevel[$target];
                }
            }
        }

        $mapping = [];
        foreach ($guessed as $header => $target) {
            // first column wins when two headers look like the same field
            if ($target !== null && !in_array($target, $mapping, true)) {
                $mapping[$header] = $target;
            }
        }

        return $mapping;
    }

    private function guess(string $header): ?string
    {
        // the template headers themselves
        $default = $this->getDefaultMapping();
        if (isset($default[$header])) {
            return $default[$header];
        }

        // a percent sign is the only thing telling "TVA %" (a rate) from "TVA" (an amount)
        $raw = mb_strtolower($header);
        $normalized = self::normalize($header);
        if ($normalized === '') {
            return null;
        }
        if (str_contains($raw, '%') && str_contains($normalized, 'tva')) {
            return 'lineVatRate';
        }

        $partnerName = $this->isIssued() ? 'receiverName' : 'senderName';
        $partnerCif = $this->isIssued() ? 'receiverCif' : 'senderCif';
        foreach (self::ALIASES as $target => $aliases) {
            if (!in_array($normalized, $aliases, true)) {
                continue;
            }
            if ($target === 'partnerName' || $target === 'supplierName') {
                if (($target === 'partnerName') !== $this->isIssued()) {
                    continue;
                }

                return $partnerName;
            }

            if ($target === 'clientCif' || $target === 'supplierCif') {
                // the other party's code: kept only to check that the file belongs to this company
                return ($target === 'clientCif') === $this->isIssued() ? $partnerCif : ($this->isIssued() ? 'senderCif' : 'receiverCif');
            }

            return $target === 'partnerCif' ? $partnerCif : $target;
        }

        return null;
    }

    private static function normalize(string $header): string
    {
        $ascii = strtr(mb_strtolower(trim($header)), [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
        ]);
        $ascii = preg_replace('/\([^)]*\)/', ' ', $ascii) ?? $ascii; // "(RON)", "(lei)"
        $ascii = preg_replace('/[^a-z0-9%]+/', ' ', $ascii) ?? '';
        $ascii = trim(preg_replace('/\s+/', ' ', $ascii) ?? '');
        // "%" kept only where an alias has it
        return in_array($ascii, ['tva %', 'cota tva %', '% tva', 'vat %'], true) ? $ascii : trim(str_replace('%', '', $ascii));
    }

    /**
     * Share of the columns an invoice report cannot do without (number, date, partner, an amount).
     *
     * @param string[] $headers
     */
    public function detectConfidence(array $headers): float
    {
        $targets = array_values($this->suggestMapping($headers));
        $has = static fn (array $any) => array_intersect($any, $targets) !== [] ? 1 : 0;

        $found = $has(['number'])
            + $has(['issueDate'])
            + $has(['receiverName', 'receiverCif', 'senderName', 'senderCif'])
            + $has(['total', 'subtotal', 'lineTotal', 'lineTotalGross', 'lineUnitPrice']);

        // a specific program's layout wins over this one when both recognise the file
        return round($found / 4 * 0.9, 2);
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

        $result = ['direction' => $this->isIssued() ? 'outgoing' : 'incoming'];

        // --- identity ------------------------------------------------------
        $number = $v['number'] ?? '';
        $series = $v['series'] ?? '';
        if ($number !== '' && preg_match('/^\d+\.0+$/', $number)) {
            $number = (string) (int) $number; // spreadsheet number cell: "125.0"
        }
        if ($number !== '' && $series !== '' && stripos($number, $series) !== 0) {
            $number = $series . $number;
        }
        $result['number'] = $number;

        foreach (['issueDate', 'dueDate'] as $field) {
            if (isset($v[$field])) {
                $date = self::normalizeDate($v[$field]);
                // an unreadable date is left as it is so that the row is reported, not silently undated
                $result[$field] = $date ?? $v[$field];
            }
        }

        foreach (['receiverName', 'senderName', 'notes', 'clientEmail', 'clientPhone', 'clientAddress', 'clientCity', 'clientCounty'] as $field) {
            if (isset($v[$field])) {
                $result[$field] = $v[$field];
            }
        }
        foreach (['receiverCif', 'senderCif'] as $field) {
            if (isset($v[$field])) {
                $cif = strtoupper(preg_replace('/[\s.\-]+/', '', $v[$field]) ?? $v[$field]);
                if ($cif !== '' && $cif !== '0' && $cif !== '-') {
                    $result[$field] = $cif;
                }
            }
        }
        if (isset($v['clientCountry'])) {
            $country = strtoupper($v['clientCountry']);
            $country = ['ROMANIA' => 'RO', 'ROMÂNIA' => 'RO'][$country] ?? $country;
            if (preg_match('/^[A-Z]{2}$/', $country)) {
                $result['clientCountry'] = $country;
            }
        }

        if (isset($v['currency'])) {
            $currency = strtoupper($v['currency']);
            $currency = ['LEI' => 'RON', 'LEU' => 'RON', 'EURO' => 'EUR', '€' => 'EUR', '$' => 'USD'][$currency] ?? $currency;
            if (preg_match('/^[A-Z]{3}$/', $currency)) {
                $result['currency'] = $currency;
            }
        }
        if (isset($v['exchangeRate'])) {
            $rate = $this->amount($v['exchangeRate']);
            if ($rate !== null && $rate > 0 && $rate != 1.0) {
                $result['exchangeRate'] = (string) $rate;
            }
        }
        if (isset($v['paymentMethod'])) {
            $method = self::paymentMethod($v['paymentMethod']);
            if ($method !== null) {
                $result['paymentMethod'] = $method;
            }
        }

        // --- amounts -------------------------------------------------------
        $subtotal = isset($v['subtotal']) ? $this->amount($v['subtotal']) : null;
        $vatTotal = isset($v['vatTotal']) ? $this->amount($v['vatTotal']) : null;
        $total = isset($v['total']) ? $this->amount($v['total']) : null;
        $rate = isset($v['lineVatRate']) ? self::vatRate($v['lineVatRate']) : null;

        $hasLine = false;
        foreach (['lineDescription', 'lineQuantity', 'lineUnitPrice', 'lineTotal', 'lineTotalGross', 'lineVatAmount', 'lineProductCode'] as $field) {
            $hasLine = $hasLine || isset($v[$field]);
        }

        $unknownSplit = false;
        if ($hasLine) {
            // Per-line row: the invoice amounts are this line's; the persister adds the rows up.
            $quantity = isset($v['lineQuantity']) ? $this->amount($v['lineQuantity']) : null;
            $unitPrice = isset($v['lineUnitPrice']) ? $this->amount($v['lineUnitPrice']) : null;
            $net = isset($v['lineTotal']) ? $this->amount($v['lineTotal']) : null;
            $gross = isset($v['lineTotalGross']) ? $this->amount($v['lineTotalGross']) : null;
            $vat = isset($v['lineVatAmount']) ? $this->amount($v['lineVatAmount']) : null;

            if ($net === null && $unitPrice !== null) {
                $net = round($unitPrice * ($quantity ?? 1.0), 2);
            }
            // a line that carries no amount of its own takes the invoice-level ones
            if ($net === null && $gross === null) {
                [$net, $vat, $gross] = [$subtotal, $vat ?? $vatTotal, $total];
            }
        } else {
            [$net, $vat, $gross, $quantity, $unitPrice] = [$subtotal, $vatTotal, $total, null, null];
        }

        if ($net === null && $gross !== null) {
            if ($vat !== null) {
                $net = round($gross - $vat, 2);
            } elseif ($rate !== null) {
                $net = round($gross / (1 + $rate / 100), 2);
            } else {
                $unknownSplit = true; // gross only: the persister splits it with the company's own rate
                $net = $gross;
            }
        }
        if ($net !== null && $vat === null) {
            $vat = $gross !== null && !$unknownSplit ? round($gross - $net, 2) : ($rate !== null ? round($net * $rate / 100, 2) : 0.0);
        }
        if ($net !== null && $gross === null) {
            $gross = round($net + ($vat ?? 0.0), 2);
        }

        if ($net !== null) {
            $quantity = $quantity ?? 1.0;
            if ($quantity == 0.0) {
                $quantity = 1.0;
            }
            if ($rate === null && !$unknownSplit) {
                $rate = abs($net) > 0.004 ? round(($vat ?? 0.0) / $net * 100) : 0.0;
            }
            $line = [
                'description'   => $v['lineDescription'] ?? ('Conform facturii ' . $number),
                'quantity'      => (string) $quantity,
                'unitPrice'     => self::money($unitPrice ?? $net / $quantity),
                'vatAmount'     => self::money($vat ?? 0.0),
                'unitOfMeasure' => $v['lineUnitOfMeasure'] ?? 'buc',
            ];
            if (isset($v['lineProductCode'])) {
                $line['productCode'] = $v['lineProductCode'];
            }
            if ($unknownSplit) {
                $line['lineTotal'] = self::money($gross);
                $result['_vatIncludedUnknownRate'] = true;
            } else {
                $line['lineTotal'] = self::money($net);
                $line['vatRate'] = number_format((float) $rate, 2, '.', '');
                if ($rate > 0) {
                    $line['vatCategoryCode'] = 'S';
                }
            }
            $result['lines'] = [$line];
            $result['subtotal'] = self::money($net);
            $result['vatTotal'] = self::money($vat ?? 0.0);
            $result['total'] = self::money($gross);
        }

        // --- collection state ----------------------------------------------
        $state = isset($v['state']) ? self::normalize($v['state']) : '';
        if ($state !== '' && preg_match('/\b(anulat|anulata|stornat|stornata|cancelled|canceled|void)\b/', $state)) {
            $result['status'] = 'cancelled';
        } elseif ($gross !== null) {
            $paid = isset($v['paidAmount']) ? $this->amount($v['paidAmount']) : null;
            if ($paid === null && isset($v['paidAmount']) && $state === '') {
                $state = self::normalize($v['paidAmount']); // an "Incasat" column holding Da / Nu
            }
            $unpaid = isset($v['unpaidAmount']) ? $this->amount($v['unpaidAmount']) : null;
            if ($paid === null && $unpaid !== null) {
                $result['_unpaidAmount'] = self::money(max($unpaid, 0.0)); // collected = invoice total − this
            }
            if ($paid === null && $state !== '') {
                if (preg_match('/\b(ne\w+|partial\w*|unpaid|restant\w*|scadent\w*|nu|no|0)\b/', $state)) {
                    $paid = null;
                } elseif (preg_match('/\b(incasat\w*|achitat\w*|platit\w*|paid|da|yes|1)\b/', $state)) {
                    $result['_paid'] = true;
                }
            }
            // an amount is the invoice's, repeated on each of its rows: the persister compares
            // it with the invoice total once all the rows are in
            if ($paid !== null) {
                $result['_paidAmount'] = self::money(max($paid, 0.0));
            }
        }

        // A storno names the invoice it reverses: the persister links the two.
        if (isset($v['stornoOf'])) {
            $result['_stornoOf'] = $v['stornoOf'];
        }

        // Missing partners are created from the invoice itself (issued invoices only).
        if ($this->isIssued()) {
            $result['_createClient'] = true;
        }

        return $result;
    }

    /** Amount as a float; null when the cell holds no number. */
    private function amount(string $raw): ?float
    {
        $clean = preg_replace('/[^\d,.\-]/u', '', $raw) ?? '';
        if ($clean === '' || $clean === '-') {
            return null;
        }
        // "1,234.56" (comma thousands) vs "1.234,56" (dot thousands)
        if (str_contains($clean, ',') && str_contains($clean, '.') && strrpos($clean, ',') < strrpos($clean, '.')) {
            $clean = str_replace(',', '', $clean);
        }
        $normalized = $this->normalizeAmount($clean);

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    /** "19", "19%", "19,00 %", "0.19" → 19.0 */
    private static function vatRate(string $raw): ?float
    {
        $clean = str_replace(',', '.', preg_replace('/[^\d,.]/', '', $raw) ?? '');
        if ($clean === '' || !is_numeric($clean)) {
            return null;
        }
        $rate = (float) $clean;
        if ($rate > 0 && $rate < 1) {
            $rate *= 100; // percent-formatted spreadsheet cell
        }

        return $rate <= 100 ? round($rate, 2) : null;
    }

    /** Every usual way a date is written in an export, as Y-m-d; null when it is not a date. */
    public static function normalizeDate(string $raw): ?string
    {
        $raw = trim($raw);
        // spreadsheet date cell read as its serial number
        if (preg_match('/^\d{5}(\.\d+)?$/', $raw) && (float) $raw > 20000 && (float) $raw < 80000) {
            return (new \DateTimeImmutable('1899-12-30'))->modify('+' . (int) $raw . ' days')->format('Y-m-d');
        }
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $raw, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})/', $raw, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2})$/', $raw, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], 2000 + (int) $m[3]];
        } else {
            return null;
        }

        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    private static function paymentMethod(string $raw): ?string
    {
        $text = self::normalize($raw);

        return match (true) {
            (bool) preg_match('/numerar|cash|chitanta/', $text) => 'cash',
            (bool) preg_match('/card|pos\b/', $text) => 'card',
            (bool) preg_match('/transfer|ordin|\bop\b|banca|bancar|virament|bank/', $text) => 'bank_transfer',
            default => null,
        };
    }
}
