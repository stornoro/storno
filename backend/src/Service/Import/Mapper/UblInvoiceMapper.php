<?php

namespace App\Service\Import\Mapper;

use App\Service\Import\Parser\UblInvoiceFileParser;

/**
 * Invoice history from e-Factura XML files. The parser hands over rows whose columns already
 * carry the names of the target fields, so the mapping is the identity and the amounts, the
 * partners and the collection state are worked out the way they are for a spreadsheet.
 */
class UblInvoiceMapper extends GenericInvoiceMapper
{
    public const SOURCE = 'efactura';

    public function __construct(string $importType = self::TYPE_ISSUED)
    {
        parent::__construct(self::SOURCE, $importType);
    }

    /**
     * @return array<string, string>
     */
    public function getDefaultMapping(): array
    {
        return array_combine(UblInvoiceFileParser::HEADERS, UblInvoiceFileParser::HEADERS);
    }

    /**
     * @param string[] $headers
     * @return array<string, string>
     */
    public function suggestMapping(array $headers): array
    {
        return array_intersect_key($this->getDefaultMapping(), array_flip(array_map('strval', $headers)));
    }

    /**
     * @param string[] $headers
     */
    public function detectConfidence(array $headers): float
    {
        return array_diff(['number', 'issueDate', 'senderCif', 'receiverCif', 'lineTotal'], $headers) === [] ? 1.0 : 0.0;
    }
}
