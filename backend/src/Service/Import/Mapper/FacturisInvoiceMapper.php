<?php

namespace App\Service\Import\Mapper;

/**
 * Facturis sales journal (XLSX / CSV). One row per invoice line; rows sharing a "Nr. Document"
 * are gathered into one invoice by the persister.
 *
 * The journal has a fixed core (the columns below), but users add or rename columns before they
 * export: a due date, the amount still to collect, a state. Those are read by their usual names,
 * the way a generic spreadsheet is, so the collection state and the due date come in too.
 */
class FacturisInvoiceMapper extends GenericInvoiceMapper
{
    private const ANCHORS = ['Nr. Document', 'Nume Client', 'Cod Fiscal', 'Pret fara TVA', 'Cota TVA'];

    public function __construct()
    {
        parent::__construct('facturis', self::TYPE_ISSUED);
    }

    /**
     * The journal's own layout, used when a job has no confirmed mapping.
     *
     * @return array<string, string>
     */
    public function getDefaultMapping(): array
    {
        return [
            'Nr. Document'     => 'number',
            'Nume Client'      => 'receiverName',
            'Cod Fiscal'       => 'receiverCif',
            'Data'             => 'issueDate',
            'Mod de Plata'     => 'paymentMethod',
            'Moneda'           => 'currency',
            'Observatii 1'     => 'notes',
            'Cod Produs'       => 'lineProductCode',
            'Nume produs'      => 'lineDescription',
            'UM'               => 'lineUnitOfMeasure',
            'Cantitate'        => 'lineQuantity',
            'Pret fara TVA'    => 'lineUnitPrice',
            'Valoare fara TVA' => 'lineTotal',
            'TVA'              => 'lineVatAmount',
            'Cota TVA'         => 'lineVatRate',
        ];
    }

    /**
     * The fixed columns keep their meaning; every other header is recognised by its usual name.
     *
     * @param string[] $headers
     * @return array<string, string>
     */
    public function suggestMapping(array $headers): array
    {
        $fixed = [];
        foreach ($this->getDefaultMapping() as $header => $target) {
            $fixed[mb_strtolower($header)] = $target;
        }

        $mapping = [];
        foreach ($headers as $header) {
            $target = $fixed[mb_strtolower(trim((string) $header))] ?? null;
            if ($target !== null && !in_array($target, $mapping, true)) {
                $mapping[(string) $header] = $target;
            }
        }
        foreach (parent::suggestMapping($headers) as $header => $target) {
            if (!isset($mapping[$header]) && !in_array($target, $mapping, true)) {
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
        return max($this->ratioFound(self::ANCHORS, $headers), parent::detectConfidence($headers));
    }
}
