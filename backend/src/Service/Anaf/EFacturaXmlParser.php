<?php

namespace App\Service\Anaf;

use App\DTO\Sync\ParsedAttachment;
use App\DTO\Sync\ParsedInvoice;
use App\DTO\Sync\ParsedInvoiceLine;
use App\DTO\Sync\ParsedParty;
use App\Enum\DocumentType;

class EFacturaXmlParser
{
    private const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
    private const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    private const NS_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
    private const NS_CREDIT_NOTE = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2';

    public function parse(string $xml): ParsedInvoice
    {
        $doc = new \DOMDocument();
        $doc->loadXML($xml);

        $root = $doc->documentElement;
        $localName = $root->localName;

        $documentType = $localName === 'CreditNote' ? DocumentType::CREDIT_NOTE : DocumentType::INVOICE;
        $lineTag = $localName === 'CreditNote' ? 'CreditNoteLine' : 'InvoiceLine';

        $seller = $this->parseParty($root, 'AccountingSupplierParty');
        $buyer = $this->parseParty($root, 'AccountingCustomerParty');

        // Parse bank account from PaymentMeans and attach to seller
        if ($seller) {
            $paymentMeansEl = $this->getFirstElement($root, self::NS_CAC, 'PaymentMeans');
            if ($paymentMeansEl) {
                $payeeAccount = $this->getFirstElement($paymentMeansEl, self::NS_CAC, 'PayeeFinancialAccount');
                if ($payeeAccount) {
                    $iban = $this->getElementValue($payeeAccount, self::NS_CBC, 'ID');
                    $branchEl = $this->getFirstElement($payeeAccount, self::NS_CAC, 'FinancialInstitutionBranch');
                    $bankName = $branchEl ? $this->getElementValue($branchEl, self::NS_CBC, 'Name') : null;
                    if ($iban) {
                        $seller = new ParsedParty(
                            cif: $seller->cif,
                            name: $seller->name,
                            vatCode: $seller->vatCode,
                            registrationNumber: $seller->registrationNumber,
                            address: $seller->address,
                            city: $seller->city,
                            county: $seller->county,
                            country: $seller->country,
                            postalCode: $seller->postalCode,
                            phone: $seller->phone,
                            email: $seller->email,
                            bankAccount: $iban,
                            bankName: $bankName,
                        );
                    }
                }
            }
        }

        $lines = $this->parseLines($root, $lineTag);

        $monetaryTotal = $this->getFirstElement($root, self::NS_CAC, 'LegalMonetaryTotal');

        $subtotal = '0.00';
        $total = '0.00';
        if ($monetaryTotal) {
            $subtotal = $this->getElementValue($monetaryTotal, self::NS_CBC, 'TaxExclusiveAmount') ?? '0.00';
            $total = $this->getElementValue($monetaryTotal, self::NS_CBC, 'PayableAmount') ?? '0.00';
        }

        $taxTotal = $this->getFirstElement($root, self::NS_CAC, 'TaxTotal');
        $vatTotal = '0.00';
        if ($taxTotal) {
            $vatTotal = $this->getElementValue($taxTotal, self::NS_CBC, 'TaxAmount') ?? '0.00';
        }

        $paymentTerms = null;
        $paymentTermsEl = $this->getFirstElement($root, self::NS_CAC, 'PaymentTerms');
        if ($paymentTermsEl) {
            $paymentTerms = $this->getElementValue($paymentTermsEl, self::NS_CBC, 'Note');
        }

        // Collect all Note elements (invoices often have multiple notes)
        $notes = $this->getAllElementValues($root, self::NS_CBC, 'Note');

        // BT-71: Delivery location
        $deliveryLocation = null;
        $deliveryEl = $this->getFirstElement($root, self::NS_CAC, 'Delivery');
        if ($deliveryEl) {
            $deliveryLocEl = $this->getFirstElement($deliveryEl, self::NS_CAC, 'DeliveryLocation');
            if ($deliveryLocEl) {
                $addressEl = $this->getFirstElement($deliveryLocEl, self::NS_CAC, 'Address');
                if ($addressEl) {
                    $parts = array_filter([
                        $this->getElementValue($addressEl, self::NS_CBC, 'StreetName'),
                        $this->getElementValue($addressEl, self::NS_CBC, 'CityName'),
                        $this->getElementValue($addressEl, self::NS_CBC, 'CountrySubentity'),
                    ]);
                    $deliveryLocation = implode(', ', $parts) ?: null;
                }
            }
            // Fallback: try DeliveryAddress directly under Delivery
            if (!$deliveryLocation) {
                $addressEl = $this->getFirstElement($deliveryEl, self::NS_CAC, 'DeliveryAddress');
                if ($addressEl) {
                    $parts = array_filter([
                        $this->getElementValue($addressEl, self::NS_CBC, 'StreetName'),
                        $this->getElementValue($addressEl, self::NS_CBC, 'CityName'),
                        $this->getElementValue($addressEl, self::NS_CBC, 'CountrySubentity'),
                    ]);
                    $deliveryLocation = implode(', ', $parts) ?: null;
                }
            }
        }

        // BT-11: Project reference
        $projectReference = null;
        $projectRefEl = $this->getFirstElement($root, self::NS_CAC, 'ProjectReference');
        if ($projectRefEl) {
            $projectReference = $this->getElementValue($projectRefEl, self::NS_CBC, 'ID');
        }

        // Attachments from AdditionalDocumentReference
        $attachments = $this->parseAttachments($root);

        return new ParsedInvoice(
            number: $this->getElementValue($root, self::NS_CBC, 'ID'),
            issueDate: $this->getElementValue($root, self::NS_CBC, 'IssueDate'),
            dueDate: $this->getElementValue($root, self::NS_CBC, 'DueDate'),
            currency: $this->getElementValue($root, self::NS_CBC, 'DocumentCurrencyCode') ?? 'RON',
            subtotal: $subtotal,
            vatTotal: $vatTotal,
            total: $total,
            documentType: $documentType,
            notes: $notes ? implode("\n", $notes) : null,
            paymentTerms: $paymentTerms,
            seller: $seller,
            buyer: $buyer,
            lines: $lines,
            deliveryLocation: $deliveryLocation,
            projectReference: $projectReference,
            attachments: $attachments,
        );
    }

    private function parseParty(\DOMElement $root, string $accountingPartyTag): ?ParsedParty
    {
        $accountingParty = $this->getFirstElement($root, self::NS_CAC, $accountingPartyTag);
        if (!$accountingParty) {
            return null;
        }

        $party = $this->getFirstElement($accountingParty, self::NS_CAC, 'Party');
        if (!$party) {
            return null;
        }

        // Party name
        $partyNameEl = $this->getFirstElement($party, self::NS_CAC, 'PartyName');
        $name = $partyNameEl ? $this->getElementValue($partyNameEl, self::NS_CBC, 'Name') : null;

        // CIF from PartyTaxScheme/CompanyID (primary source)
        $cif = null;
        $vatCode = null;
        $taxSchemeEl = $this->getFirstElement($party, self::NS_CAC, 'PartyTaxScheme');
        if ($taxSchemeEl) {
            $vatCode = $this->getElementValue($taxSchemeEl, self::NS_CBC, 'CompanyID');
            $cif = $vatCode;
        }

        // Fallback: CIF from PartyIdentification/ID
        if (!$cif) {
            $partyIdEl = $this->getFirstElement($party, self::NS_CAC, 'PartyIdentification');
            if ($partyIdEl) {
                $cif = $this->getElementValue($partyIdEl, self::NS_CBC, 'ID');
            }
        }

        // Registration number from PartyLegalEntity
        $regNumber = null;
        $legalEl = $this->getFirstElement($party, self::NS_CAC, 'PartyLegalEntity');
        if ($legalEl) {
            $regNumber = $this->getElementValue($legalEl, self::NS_CBC, 'CompanyID');
            if (!$name) {
                $name = $this->getElementValue($legalEl, self::NS_CBC, 'RegistrationName');
            }
        }

        // Postal address
        $address = null;
        $city = null;
        $county = null;
        $country = 'RO';
        $postalCode = null;
        $postalEl = $this->getFirstElement($party, self::NS_CAC, 'PostalAddress');
        if ($postalEl) {
            $address = $this->getElementValue($postalEl, self::NS_CBC, 'StreetName');
            $city = $this->getElementValue($postalEl, self::NS_CBC, 'CityName');
            $rawCounty = $this->getElementValue($postalEl, self::NS_CBC, 'CountrySubentity');
            $county = $rawCounty ? $this->normalizeCounty($rawCounty) : null;
            $postalCode = $this->getElementValue($postalEl, self::NS_CBC, 'PostalZone');

            $countryEl = $this->getFirstElement($postalEl, self::NS_CAC, 'Country');
            if ($countryEl) {
                $country = $this->getElementValue($countryEl, self::NS_CBC, 'IdentificationCode') ?? 'RO';
            }
        }

        // Contact
        $phone = null;
        $email = null;
        $contactEl = $this->getFirstElement($party, self::NS_CAC, 'Contact');
        if ($contactEl) {
            $phone = $this->getElementValue($contactEl, self::NS_CBC, 'Telephone');
            $email = $this->getElementValue($contactEl, self::NS_CBC, 'ElectronicMail');
        }

        // Clean CIF — strip RO prefix for numeric-only storage
        $cleanCif = $cif;
        if ($cleanCif && preg_match('/^RO(\d+)$/i', $cleanCif, $m)) {
            $cleanCif = $m[1];
        }

        return new ParsedParty(
            cif: $cleanCif,
            name: $name,
            vatCode: $vatCode,
            registrationNumber: $regNumber,
            address: $address,
            city: $city,
            county: $county,
            country: $country,
            postalCode: $postalCode,
            phone: $phone,
            email: $email,
        );
    }

    /**
     * @return ParsedInvoiceLine[]
     */
    private function parseLines(\DOMElement $root, string $lineTag): array
    {
        $lines = [];
        $lineElements = $root->getElementsByTagNameNS(self::NS_CAC, $lineTag);

        foreach ($lineElements as $lineEl) {
            $description = '';
            $itemEl = $this->getFirstElement($lineEl, self::NS_CAC, 'Item');
            if ($itemEl) {
                $description = $this->getElementValue($itemEl, self::NS_CBC, 'Name') ?? '';
                if (!$description) {
                    $description = $this->getElementValue($itemEl, self::NS_CBC, 'Description') ?? '';
                }
            }

            $quantity = $this->getElementValue($lineEl, self::NS_CBC, 'InvoicedQuantity')
                ?? $this->getElementValue($lineEl, self::NS_CBC, 'CreditedQuantity')
                ?? '1';

            $unitOfMeasure = 'buc';
            $qtyNodes = $lineEl->getElementsByTagNameNS(self::NS_CBC, 'InvoicedQuantity');
            if ($qtyNodes->length === 0) {
                $qtyNodes = $lineEl->getElementsByTagNameNS(self::NS_CBC, 'CreditedQuantity');
            }
            if ($qtyNodes->length > 0) {
                $unitOfMeasure = $qtyNodes->item(0)->getAttribute('unitCode') ?: 'buc';
            }

            $unitPrice = '0.00';
            $priceEl = $this->getFirstElement($lineEl, self::NS_CAC, 'Price');
            if ($priceEl) {
                $unitPrice = $this->getElementValue($priceEl, self::NS_CBC, 'PriceAmount') ?? '0.00';
            }

            $vatRate = '21.00';
            $vatCategoryCode = 'S';
            if ($itemEl) {
                $taxCatEl = $this->getFirstElement($itemEl, self::NS_CAC, 'ClassifiedTaxCategory');
                if ($taxCatEl) {
                    $vatRate = $this->getElementValue($taxCatEl, self::NS_CBC, 'Percent') ?? '21.00';
                    $vatCategoryCode = $this->getElementValue($taxCatEl, self::NS_CBC, 'ID') ?? 'S';
                }
            }

            $lineTotal = $this->getElementValue($lineEl, self::NS_CBC, 'LineExtensionAmount') ?? '0.00';

            // Calculate VAT amount for line
            $vatAmount = bcmul($lineTotal, bcdiv($vatRate, '100', 6), 2);

            $lines[] = new ParsedInvoiceLine(
                description: $description,
                quantity: $quantity,
                unitOfMeasure: $unitOfMeasure,
                unitPrice: $unitPrice,
                vatRate: $vatRate,
                vatCategoryCode: $vatCategoryCode,
                vatAmount: $vatAmount,
                lineTotal: $lineTotal,
            );
        }

        return $lines;
    }

    private function getFirstElement(\DOMElement $parent, string $ns, string $localName): ?\DOMElement
    {
        $nodes = $parent->getElementsByTagNameNS($ns, $localName);
        if ($nodes->length === 0) {
            return null;
        }

        $el = $nodes->item(0);
        return $el instanceof \DOMElement ? $el : null;
    }

    private function getElementValue(\DOMElement $parent, string $ns, string $localName): ?string
    {
        $el = $this->getFirstElement($parent, $ns, $localName);
        if (!$el) {
            return null;
        }

        $value = trim($el->textContent);
        return $value !== '' ? $value : null;
    }

    /**
     * @return ParsedAttachment[]
     */
    private function parseAttachments(\DOMElement $root): array
    {
        $attachments = [];
        $docRefs = $root->getElementsByTagNameNS(self::NS_CAC, 'AdditionalDocumentReference');

        foreach ($docRefs as $docRef) {
            if (!$docRef instanceof \DOMElement) {
                continue;
            }

            $attachmentEl = $this->getFirstElement($docRef, self::NS_CAC, 'Attachment');
            if (!$attachmentEl) {
                continue;
            }

            $embeddedDoc = $this->getFirstElement($attachmentEl, self::NS_CBC, 'EmbeddedDocumentBinaryObject');
            if (!$embeddedDoc) {
                continue;
            }

            $content = trim($embeddedDoc->textContent);
            if ($content === '') {
                continue;
            }

            $filename = $embeddedDoc->getAttribute('filename') ?: null;
            $mimeType = $embeddedDoc->getAttribute('mimeCode') ?: 'application/octet-stream';
            $description = $this->getElementValue($docRef, self::NS_CBC, 'DocumentDescription');

            $attachments[] = new ParsedAttachment(
                filename: $filename,
                mimeType: $mimeType,
                description: $description,
                content: $content,
            );
        }

        return $attachments;
    }

    /**
     * Normalize county values from UBL XML to ISO 3166-2:RO codes (e.g. "RO-AB" → "AB", "Alba" → "AB").
     * Returns the ISO code for storage — the rest of the system uses codes consistently.
     */
    private function normalizeCounty(string $value): string
    {
        // Valid ISO codes
        static $validCodes = [
            'AB', 'AG', 'AR', 'B', 'BC', 'BH', 'BN', 'BR', 'BT', 'BV', 'BZ',
            'CJ', 'CL', 'CS', 'CT', 'CV', 'DB', 'DJ', 'GJ', 'GL', 'GR',
            'HD', 'HR', 'IF', 'IL', 'IS', 'MH', 'MM', 'MS', 'NT', 'OT',
            'PH', 'SB', 'SJ', 'SM', 'SV', 'TL', 'TM', 'TR', 'VL', 'VN', 'VS',
        ];

        // Reverse map: full name → ISO code
        static $nameToCode = [
            'ALBA' => 'AB', 'ARGES' => 'AG', 'ARAD' => 'AR', 'BUCURESTI' => 'B',
            'BACAU' => 'BC', 'BIHOR' => 'BH', 'BISTRITA-NASAUD' => 'BN',
            'BRAILA' => 'BR', 'BOTOSANI' => 'BT', 'BRASOV' => 'BV', 'BUZAU' => 'BZ',
            'CLUJ' => 'CJ', 'CALARASI' => 'CL', 'CARAS-SEVERIN' => 'CS',
            'CONSTANTA' => 'CT', 'COVASNA' => 'CV', 'DAMBOVITA' => 'DB', 'DOLJ' => 'DJ',
            'GORJ' => 'GJ', 'GALATI' => 'GL', 'GIURGIU' => 'GR',
            'HUNEDOARA' => 'HD', 'HARGHITA' => 'HR', 'ILFOV' => 'IF',
            'IALOMITA' => 'IL', 'IASI' => 'IS', 'MEHEDINTI' => 'MH',
            'MARAMURES' => 'MM', 'MURES' => 'MS', 'NEAMT' => 'NT', 'OLT' => 'OT',
            'PRAHOVA' => 'PH', 'SIBIU' => 'SB', 'SALAJ' => 'SJ', 'SATU MARE' => 'SM',
            'SUCEAVA' => 'SV', 'TULCEA' => 'TL', 'TIMIS' => 'TM',
            'TELEORMAN' => 'TR', 'VALCEA' => 'VL', 'VRANCEA' => 'VN', 'VASLUI' => 'VS',
        ];

        // Strip "RO-" prefix if present
        $code = strtoupper(trim($value));
        if (str_starts_with($code, 'RO-')) {
            $code = substr($code, 3);
        }

        // Already a valid ISO code
        if (in_array($code, $validCodes, true)) {
            return $code;
        }

        // Full name → ISO code
        return $nameToCode[$code] ?? $value;
    }

    /**
     * @return string[] All non-empty text values for direct child elements matching the given name.
     */
    private function getAllElementValues(\DOMElement $parent, string $ns, string $localName): array
    {
        $values = [];
        foreach ($parent->childNodes as $node) {
            if ($node instanceof \DOMElement && $node->localName === $localName && $node->namespaceURI === $ns) {
                $value = trim($node->textContent);
                if ($value !== '') {
                    $values[] = $value;
                }
            }
        }

        return $values;
    }
}
