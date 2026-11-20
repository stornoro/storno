<?php

namespace App\Service\Import\Parser;

use App\DTO\Sync\ParsedInvoice;
use App\Enum\DocumentType;
use App\Service\Anaf\EFacturaXmlParser;

/**
 * e-Factura XML files (UBL Invoice / CreditNote), one file or a ZIP of them, read as invoice
 * history: one row per invoice line, carrying the invoice header next to the line.
 *
 * A ZIP may hold anything SPV hands out: the signature files and other XML that is not an
 * invoice are passed over. A credit note comes in with negative quantities and amounts, the
 * way a storno invoice is kept in the books.
 */
class UblInvoiceFileParser implements FileParserInterface
{
    public const HEADERS = [
        'number', 'issueDate', 'dueDate', 'senderName', 'senderCif', 'receiverName', 'receiverCif',
        'currency', 'notes', 'stornoOf',
        'lineDescription', 'lineProductCode', 'lineQuantity', 'lineUnitOfMeasure', 'lineUnitPrice',
        'lineVatRate', 'lineVatAmount', 'lineTotal',
        'clientEmail', 'clientPhone', 'clientAddress', 'clientCity', 'clientCounty', 'clientCountry',
    ];

    private const MAX_FILES = 5000;

    public function __construct(private readonly EFacturaXmlParser $xmlParser)
    {
    }

    public function supports(string $fileFormat): bool
    {
        return in_array($fileFormat, ['ubl_xml', 'ubl_zip'], true);
    }

    public function parse(string $filePath): \Generator
    {
        foreach ($this->documents($filePath) as $name => $xml) {
            yield from $this->rows($xml, $name);
        }
    }

    public function preview(string $filePath, int $maxRows = 20): array
    {
        $rows = [];
        foreach ($this->parse($filePath) as $row) {
            $rows[] = $row;
            if (count($rows) >= $maxRows) {
                break;
            }
        }

        return ['headers' => self::HEADERS, 'rows' => $rows];
    }

    public function countRows(string $filePath): int
    {
        return iterator_count($this->parse($filePath));
    }

    /**
     * @return \Generator<int, array<string, string>>
     */
    private function rows(string $xml, string $name): \Generator
    {
        $doc = new \DOMDocument();
        if (!@$doc->loadXML($xml, LIBXML_NONET) || $doc->documentElement === null) {
            return;
        }
        if (!in_array($doc->documentElement->localName, ['Invoice', 'CreditNote'], true)) {
            return; // signature, ANAF response or any other XML
        }

        try {
            $parsed = $this->xmlParser->parse($xml);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('Fisierul %s nu poate fi citit ca factura: %s', $name, $e->getMessage()));
        }
        if ($parsed->number === null || $parsed->number === '') {
            return;
        }

        $sign = $parsed->documentType === DocumentType::CREDIT_NOTE ? -1 : 1;
        $header = $this->header($parsed, $this->billingReference($doc));

        if ($parsed->lines === []) {
            yield $header + [
                'lineDescription' => 'Conform facturii ' . $parsed->number,
                'lineQuantity' => (string) $sign,
                'lineUnitPrice' => $parsed->subtotal,
                'lineVatAmount' => self::signed($parsed->vatTotal, $sign),
                'lineTotal' => self::signed($parsed->subtotal, $sign),
            ];

            return;
        }

        foreach ($parsed->lines as $line) {
            yield $header + [
                'lineDescription' => $line->description,
                'lineProductCode' => (string) ($line->sellerItemCode ?? ''),
                'lineQuantity' => self::signed($line->quantity, $sign),
                'lineUnitOfMeasure' => $line->unitOfMeasure,
                'lineUnitPrice' => $line->unitPrice,
                'lineVatRate' => $line->vatRate,
                'lineVatAmount' => self::signed($line->vatAmount, $sign),
                'lineTotal' => self::signed($line->lineTotal, $sign),
            ];
        }
    }

    /**
     * @return array<string, string>
     */
    private function header(ParsedInvoice $parsed, ?string $stornoOf): array
    {
        $buyer = $parsed->buyer;

        return [
            'number' => (string) $parsed->number,
            'issueDate' => (string) $parsed->issueDate,
            'dueDate' => (string) $parsed->dueDate,
            'senderName' => (string) $parsed->seller?->name,
            'senderCif' => (string) ($parsed->seller?->vatCode ?: $parsed->seller?->cif),
            'receiverName' => (string) $buyer?->name,
            'receiverCif' => (string) ($buyer?->vatCode ?: $buyer?->cif),
            'currency' => $parsed->currency,
            'notes' => (string) $parsed->notes,
            'stornoOf' => (string) $stornoOf,
            'clientEmail' => (string) $buyer?->email,
            'clientPhone' => (string) $buyer?->phone,
            'clientAddress' => (string) $buyer?->address,
            'clientCity' => (string) $buyer?->city,
            'clientCounty' => (string) $buyer?->county,
            'clientCountry' => (string) $buyer?->country,
        ];
    }

    /** The invoice a credit note or a corrective invoice refers to (BillingReference). */
    private function billingReference(\DOMDocument $doc): ?string
    {
        $xpath = new \DOMXPath($doc);
        $nodes = $xpath->query(
            '/*/*[local-name()="BillingReference"]/*[local-name()="InvoiceDocumentReference"]/*[local-name()="ID"]',
        );
        $value = $nodes !== false && $nodes->length > 0 ? trim((string) $nodes->item(0)?->textContent) : '';

        return $value !== '' ? $value : null;
    }

    private static function signed(string $amount, int $sign): string
    {
        if ($sign === 1 || !is_numeric($amount) || (float) $amount == 0.0) {
            return $amount;
        }

        return str_starts_with($amount, '-') ? substr($amount, 1) : '-' . $amount;
    }

    /**
     * @return \Generator<string, string> file name → XML content
     */
    private function documents(string $filePath): \Generator
    {
        $handle = fopen($filePath, 'rb');
        $magic = $handle ? (string) fread($handle, 4) : '';
        if ($handle) {
            fclose($handle);
        }

        if ($magic !== "PK\x03\x04") {
            $content = file_get_contents($filePath);
            if ($content === false) {
                throw new \RuntimeException('Fisierul XML nu a putut fi citit.');
            }
            yield basename($filePath) => $content;

            return;
        }

        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new \RuntimeException('Arhiva ZIP nu a putut fi deschisa.');
        }
        try {
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name !== false && preg_match('/\.xml$/i', $name) && !str_contains($name, '__MACOSX')) {
                    $names[] = $name;
                }
            }
            if (count($names) > self::MAX_FILES) {
                throw new \RuntimeException(sprintf('Arhiva are %d fisiere XML; limita este %d pe import.', count($names), self::MAX_FILES));
            }
            sort($names, SORT_NATURAL);
            foreach ($names as $name) {
                $content = $zip->getFromName($name);
                if ($content !== false && $content !== '') {
                    yield $name => $content;
                }
            }
        } finally {
            $zip->close();
        }
    }
}
