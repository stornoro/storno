<?php

namespace App\Invoice\Invoice;

use Sabre\Xml\Service;
use App\Invoice\Schema;

class GenerateInvoice
{
    public static $currencyID;

    public static function invoice(Invoice $invoice, $currencyId = 'RON')
    {
        self::$currencyID = $currencyId;

        $xmlService = new Service();

        $xmlService->namespaceMap = [
            'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2' => '',
            'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2' => 'cbc',
            'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2' => 'cac',
            'urn:oasis:names:specification:ubl:schema:xsd:QualifiedDataTypes-2' => 'qdt',
            'urn:oasis:names:specification:ubl:schema:xsd:UnqualifiedDataTypes-2' => 'udt',
            'urn:un:unece:uncefact:documentation:2' => 'ccts',
            'http://www.w3.org/2001/XMLSchema-instance' => 'xsi',
            // 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2 http://docs.oasis-open.org/ubl/os-UBL-2.1/xsd/maindoc/UBL-Invoice-2.1.xsd' => 'xsi:schemaLocation'
        ];

        return $xmlService->write('Invoice', [
            $invoice
        ]);
    }
}
