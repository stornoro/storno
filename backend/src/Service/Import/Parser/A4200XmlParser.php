<?php

namespace App\Service\Import\Parser;

/**
 * Reads the XML files a fiscal cash register (AMEF) exports for ANAF's A4200
 * reporting (OPANAF 146/2018, annex 2): a message per fiscal day whose root
 * <msj idM="…"> holds one <bon> per fiscal receipt (II.3) and, in the day's
 * closing message, one <rB> Z report (II.7); the period's <mReg> register
 * (II.12) carries no receipts and is skipped. A .zip of a month's files is
 * read file by file.
 *
 * Official receipt: <bon idB totB totTva><cote cota tva/>…</bon> — the id
 * carries the device serial (chars 1-10), the date/time (11-24), the Z report
 * number (25-28) and the receipt number (29-32); there are no product lines,
 * only totals per VAT rate. Register software that exports a richer, unsigned
 * XML is read leniently: date / time / number attributes or child elements,
 * <pl tipP valPl/> payments on the receipt, <linie|art den cant pret val cota/>
 * product lines, a customer CUI (cif / cui attribute), and a root other than
 * <msj> as long as it contains <bon> elements.
 *
 * The older element names published for the AMEF XML files (ID_BON,
 * DATA_EMITERE_BF, CENTRAL with total_bon / total_tva / total_plata_card /
 * total_plata_numerar / total_plata_bv / total_plata_altele, COTE with
 * cota / val_cota, ARTICOL with den_art / cantitate / pret / valoare / cota,
 * BENEF with cif_beneficiar) are read as aliases of the same fields.
 *
 * Every receipt becomes one flat row (the VAT breakdown, payments and product
 * lines are encoded as "key:value;key:value" cells), every Z report one row
 * of type "Z" that only feeds the daily summary.
 */
class A4200XmlParser implements FileParserInterface
{
    public const COL_TYPE = 'Tip';
    public const COL_FISCAL_ID = 'ID bon';
    public const COL_SERIAL = 'Serie AMEF';
    public const COL_DATE = 'Data';
    public const COL_TIME = 'Ora';
    public const COL_Z_NUMBER = 'Nr. raport Z';
    public const COL_NUMBER = 'Nr. bon';
    public const COL_TOTAL = 'Total bon';
    public const COL_VAT_TOTAL = 'Total TVA';
    public const COL_VAT_BREAKDOWN = 'Cote TVA';
    public const COL_PAYMENTS = 'Plăți';
    public const COL_CUSTOMER_CIF = 'CUI client';
    public const COL_LINES = 'Linii';
    public const COL_CURRENCY = 'Monedă';
    public const COL_RECEIPT_COUNT = 'Bonuri (raport Z)';

    public const HEADERS = [
        self::COL_TYPE,
        self::COL_FISCAL_ID,
        self::COL_SERIAL,
        self::COL_DATE,
        self::COL_TIME,
        self::COL_Z_NUMBER,
        self::COL_NUMBER,
        self::COL_TOTAL,
        self::COL_VAT_TOTAL,
        self::COL_VAT_BREAKDOWN,
        self::COL_PAYMENTS,
        self::COL_CUSTOMER_CIF,
        self::COL_LINES,
        self::COL_CURRENCY,
        self::COL_RECEIPT_COUNT,
    ];

    /** Children that describe the receipt itself instead of being records of their own. */
    private const CONTAINER_TAGS = ['central', 'benef', 'beneficiar', 'totaluri', 'total', 'sume'];

    public function supports(string $fileFormat): bool
    {
        return in_array($fileFormat, ['a4200_xml', 'a4200_zip'], true);
    }

    public function parse(string $filePath): \Generator
    {
        foreach ($this->documents($filePath) as $name => $xml) {
            yield from $this->rowsOf($xml, $name);
        }
    }

    public function preview(string $filePath, int $maxRows = 20): array
    {
        $rows = [];
        $devices = [];
        foreach ($this->parse($filePath) as $row) {
            if ($row[self::COL_SERIAL] !== '') {
                $devices[$row[self::COL_SERIAL]] = true;
            }
            if (count($rows) < $maxRows) {
                $rows[] = $row;
            }
        }

        return [
            'headers' => self::HEADERS,
            'rows' => $rows,
            'metadata' => ['devices' => implode(', ', array_keys($devices))],
        ];
    }

    public function countRows(string $filePath): int
    {
        $count = 0;
        foreach ($this->parse($filePath) as $_) {
            $count++;
        }

        return $count;
    }

    // -------------------------------------------------------------------------

    /**
     * @return \Generator<string, \SimpleXMLElement>
     */
    private function documents(string $filePath): \Generator
    {
        if (strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'zip' || $this->looksLikeZip($filePath)) {
            $zip = new \ZipArchive();
            if ($zip->open($filePath) !== true) {
                throw new \RuntimeException('Arhiva ZIP nu a putut fi deschisă.');
            }
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name !== false && preg_match('/\.xml$/i', $name) && !str_contains($name, '__MACOSX')) {
                    $names[] = $name;
                }
            }
            sort($names, SORT_NATURAL);
            foreach ($names as $name) {
                $content = $zip->getFromName($name);
                if ($content === false) {
                    continue;
                }
                $xml = $this->load($content, $name);
                if ($xml !== null) {
                    yield $name => $xml;
                }
            }
            $zip->close();

            return;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \RuntimeException('Fișierul XML nu a putut fi citit.');
        }
        $xml = $this->load($content, basename($filePath));
        if ($xml === null) {
            throw new \RuntimeException('Fișierul nu este un XML valid. Fișierele A4200 criptate / semnate de casa de marcat nu pot fi citite: exportați jurnalul XML necriptat.');
        }

        yield basename($filePath) => $xml;
    }

    private function looksLikeZip(string $filePath): bool
    {
        $h = fopen($filePath, 'rb');
        if ($h === false) {
            return false;
        }
        $sig = fread($h, 4);
        fclose($h);

        return $sig === "PK\x03\x04";
    }

    private function load(string $content, string $name): ?\SimpleXMLElement
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($content, \SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $xml === false ? null : $xml;
    }

    /**
     * @return \Generator<int, array<string, string>>
     */
    private function rowsOf(\SimpleXMLElement $root, string $fileName): \Generator
    {
        $rootName = $root->getName();
        if ($rootName === 'mReg') {
            return; // the period register: no receipts, nothing to import
        }

        $rootSerial = $this->attr($root, ['nui', 'serie', 'serieAMEF', 'serial']) ?? $this->serialFromId($this->attr($root, ['idM']));
        $rootCurrency = $this->attr($root, ['monRef', 'moneda', 'currency']);

        foreach ($this->descendants($root, ['bon', 'bonFiscal', 'receipt']) as $bon) {
            yield $this->receiptRow($bon, $rootSerial, $rootCurrency);
        }

        foreach ($this->descendants($root, ['rB', 'raportZ', 'zReport']) as $rb) {
            yield $this->zRow($rb, $rootSerial, $rootCurrency);
        }
    }

    /**
     * @return array<string, string>
     */
    private function receiptRow(\SimpleXMLElement $bon, ?string $rootSerial, ?string $rootCurrency): array
    {
        $fiscalId = $this->attr($bon, ['idB', 'id', 'idBon', 'id_bon']) ?? '';
        $serial = $this->attr($bon, ['nui', 'serie', 'serieAMEF']) ?? $this->serialFromId($fiscalId) ?? $rootSerial ?? '';
        [$date, $time] = $this->dateTimeFromId($fiscalId);
        $date = $this->attr($bon, ['data', 'date', 'dt', 'dataBon', 'data_emitere_bf', 'data_emitere']) ?? $date;
        $time = $this->attr($bon, ['ora', 'time', 'oraBon']) ?? $time;
        if ($date !== null && str_contains($date, ' ') && $time === null) {
            [$date, $time] = explode(' ', $date, 2);
        }
        $date = $this->normalizeDate($date);

        $zNumber = $this->attr($bon, ['nrRap', 'nrRaport', 'raportZ', 'nrZ']) ?? (strlen($fiscalId) >= 28 ? ltrim(substr($fiscalId, 24, 4), '0') ?: '0' : '');
        $number = $this->attr($bon, ['nr', 'nrBon', 'numar', 'number']) ?? (strlen($fiscalId) >= 32 ? ltrim(substr($fiscalId, 28, 4), '0') ?: '0' : '');

        $cote = [];
        foreach ($this->descendants($bon, ['cote', 'cota', 'tva']) as $c) {
            $rate = $this->attr($c, ['cota', 'nivel', 'grupa', 'rate']);
            $vat = $this->attr($c, ['tva', 'val', 'valTva', 'vat', 'val_cota']);
            $base = $this->attr($c, ['valOp', 'baza', 'net']);
            if ($rate === null && $vat === null) {
                continue;
            }
            $cote[] = trim((string) $rate) . ':' . $this->num($vat) . ($base !== null ? ':' . $this->num($base) : '');
        }

        $payments = [];
        foreach ($this->descendants($bon, ['pl', 'plata', 'payment']) as $p) {
            $type = $this->attr($p, ['tipP', 'tip', 'type']) ?? '9';
            $payments[] = trim($type) . ':' . $this->num($this->attr($p, ['valPl', 'val', 'suma', 'amount']));
        }

        $lines = [];
        foreach ($this->descendants($bon, ['linie', 'art', 'articol', 'produs', 'item', 'line']) as $l) {
            $lines[] = implode('|', [
                str_replace(['|', ';'], ' ', $this->attr($l, ['den', 'denumire', 'nume', 'name', 'den_art']) ?? 'Produs'),
                $this->num($this->attr($l, ['cant', 'cantitate', 'qty']) ?? '1'),
                $this->num($this->attr($l, ['pret', 'pu', 'price'])),
                $this->num($this->attr($l, ['val', 'valoare', 'total'])),
                trim((string) ($this->attr($l, ['cota', 'grupa', 'tva', 'rate']) ?? '')),
            ]);
        }

        // Files that report the payment split as totals instead of <pl> elements
        if ($payments === []) {
            foreach ([['cash', ['total_plata_numerar', 'totalPlataNumerar', 'totalNumerar']], ['card', ['total_plata_card', 'totalPlataCard', 'totalCard']], ['other', ['total_plata_bv', 'totalPlataBv']], ['other', ['total_plata_altele', 'totalPlataAltele']]] as [$method, $names]) {
                $value = $this->attr($bon, $names);
                if ($value !== null && (float) $this->num($value) !== 0.0) {
                    $payments[] = $method . ':' . $this->num($value);
                }
            }
        }

        return [
            self::COL_TYPE => 'bon',
            self::COL_FISCAL_ID => $fiscalId,
            self::COL_SERIAL => $serial,
            self::COL_DATE => $date ?? '',
            self::COL_TIME => $time ?? '',
            self::COL_Z_NUMBER => (string) $zNumber,
            self::COL_NUMBER => (string) $number,
            self::COL_TOTAL => $this->num($this->attr($bon, ['totB', 'total', 'tot', 'total_bon'])),
            self::COL_VAT_TOTAL => $this->num($this->attr($bon, ['totTva', 'tva', 'totalTva', 'total_tva'])),
            self::COL_VAT_BREAKDOWN => implode(';', $cote),
            self::COL_PAYMENTS => implode(';', $payments),
            self::COL_CUSTOMER_CIF => $this->attr($bon, ['cif', 'cui', 'cifClient', 'cuiClient', 'cif_beneficiar']) ?? '',
            self::COL_LINES => implode(';', $lines),
            self::COL_CURRENCY => strtoupper($this->attr($bon, ['monRef', 'moneda', 'currency']) ?? $rootCurrency ?? ''),
            self::COL_RECEIPT_COUNT => '',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function zRow(\SimpleXMLElement $rb, ?string $rootSerial, ?string $rootCurrency): array
    {
        $id = $this->attr($rb, ['idR', 'id']) ?? '';
        $serial = $this->attr($rb, ['nui', 'serie']) ?? $this->serialFromId($id) ?? $rootSerial ?? '';
        [$date, $time] = $this->dateTimeFromId($id);
        $date = $this->normalizeDate($this->attr($rb, ['data', 'date', 'dt']) ?? $date);
        $zNumber = $this->attr($rb, ['nr', 'nrRap', 'nrRaport']) ?? (strlen($id) >= 28 ? ltrim(substr($id, 24, 4), '0') ?: '0' : '');

        $cote = [];
        foreach ($this->descendants($rb, ['coteZ', 'cote', 'cota']) as $c) {
            $cote[] = trim((string) ($this->attr($c, ['cota', 'nivel']) ?? '')) . ':' . $this->num($this->attr($c, ['tva'])) . ':' . $this->num($this->attr($c, ['valOp', 'baza']));
        }
        $payments = [];
        foreach ($this->descendants($rb, ['pl', 'plata']) as $p) {
            $payments[] = trim((string) ($this->attr($p, ['tipP', 'tip']) ?? '9')) . ':' . $this->num($this->attr($p, ['valPl', 'val']));
        }

        return [
            self::COL_TYPE => 'Z',
            self::COL_FISCAL_ID => $id,
            self::COL_SERIAL => $serial,
            self::COL_DATE => $date ?? '',
            self::COL_TIME => $this->attr($rb, ['ora']) ?? $time ?? '',
            self::COL_Z_NUMBER => (string) $zNumber,
            self::COL_NUMBER => '',
            self::COL_TOTAL => $this->num($this->attr($rb, ['totB', 'total'])),
            self::COL_VAT_TOTAL => $this->num($this->attr($rb, ['totTva', 'tva'])),
            self::COL_VAT_BREAKDOWN => implode(';', $cote),
            self::COL_PAYMENTS => implode(';', $payments),
            self::COL_CUSTOMER_CIF => '',
            self::COL_LINES => '',
            self::COL_CURRENCY => strtoupper($this->attr($rb, ['monRef', 'moneda']) ?? $rootCurrency ?? ''),
            self::COL_RECEIPT_COUNT => $this->attr($rb, ['nrB', 'nrBonuri']) ?? '',
        ];
    }

    /**
     * Attribute or child element value, first name that exists (case-insensitive).
     *
     * @param string[] $names
     */
    private function attr(\SimpleXMLElement $el, array $names): ?string
    {
        $attrs = [];
        foreach ($el->attributes() as $k => $v) {
            $attrs[strtolower((string) $k)] = trim((string) $v);
        }
        $children = [];
        foreach ($el->children() as $child) {
            $childName = strtolower($child->getName());
            if ($child->count() === 0) {
                $children[$childName] = trim((string) $child);
            }
            // A container of totals or of the customer (CENTRAL, BENEF): its
            // attributes and simple children belong to the receipt itself.
            if (in_array($childName, self::CONTAINER_TAGS, true)) {
                foreach ($child->attributes() as $k => $v) {
                    $attrs[strtolower((string) $k)] ??= trim((string) $v);
                }
                foreach ($child->children() as $grandChild) {
                    if ($grandChild->count() === 0) {
                        $children[strtolower($grandChild->getName())] ??= trim((string) $grandChild);
                    }
                }
            }
        }
        foreach ($names as $name) {
            $key = strtolower($name);
            if (isset($attrs[$key]) && $attrs[$key] !== '') {
                return $attrs[$key];
            }
            if (isset($children[$key]) && $children[$key] !== '') {
                return $children[$key];
            }
        }

        return null;
    }

    /**
     * Direct children and grandchildren with one of the given names.
     *
     * @param string[] $names
     * @return \SimpleXMLElement[]
     */
    private function descendants(\SimpleXMLElement $el, array $names): array
    {
        $lower = array_map('strtolower', $names);
        $found = [];
        foreach ($el->children() as $child) {
            if (in_array(strtolower($child->getName()), $lower, true)) {
                $found[] = $child;
                continue;
            }
            // one wrapper level (e.g. <bonuri><bon/></bonuri>, <cote><cota/></cote> variants)
            foreach ($child->children() as $grandChild) {
                if (in_array(strtolower($grandChild->getName()), $lower, true)) {
                    $found[] = $grandChild;
                }
            }
        }

        return $found;
    }

    private function serialFromId(?string $id): ?string
    {
        if ($id === null || strlen($id) < 24) {
            return null;
        }

        return substr($id, 0, 10);
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function dateTimeFromId(string $id): array
    {
        if (strlen($id) < 24 || !preg_match('/^.{10}(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', $id, $m)) {
            return [null, null];
        }

        return [sprintf('%s-%s-%s', $m[1], $m[2], $m[3]), sprintf('%s:%s:%s', $m[4], $m[5], $m[6])];
    }

    private function normalizeDate(?string $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }
        foreach (['Y-m-d', 'd.m.Y', 'd/m/Y', 'Ymd', 'd-m-Y', 'Y-m-d H:i:s', 'd.m.Y H:i:s'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $date);
            if ($parsed !== false) {
                return $parsed->format('Y-m-d');
            }
        }

        return $date;
    }

    private function num(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '0.00';
        }
        $value = str_replace([' ', "\xC2\xA0"], '', $value);
        if (str_contains($value, ',') && !str_contains($value, '.')) {
            $value = str_replace(',', '.', $value);
        } elseif (str_contains($value, ',')) {
            $value = str_replace(',', '', $value);
        }

        return is_numeric($value) ? number_format((float) $value, 2, '.', '') : '0.00';
    }
}
