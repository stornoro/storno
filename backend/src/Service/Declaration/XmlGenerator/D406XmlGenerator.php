<?php

declare(strict_types=1);

namespace App\Service\Declaration\XmlGenerator;

use App\Entity\TaxDeclaration;
use App\Service\Declaration\DeclarationXmlGeneratorInterface;
use App\Service\Declaration\Populator\D406Populator;
use App\Service\Declaration\Saft\SaftTaxCodes;

/**
 * Writes the SAF-T (D406) `AuditFile` from the populated `data`: Header, MasterFiles,
 * GeneralLedgerEntries and SourceDocuments in the order the schema
 * (resources/saft/Ro_SAFT_Schema_v249_2025.xsd) fixes. Elements are written unprefixed; the
 * default namespace of the root (mfp:anaf:dgti:d406:declaratie:v1) is applied by
 * DeclarationNamespaceResolver like for every other form.
 *
 * Amounts are written as the populator left them (RON with two decimals, currency amounts
 * and the exchange rate for foreign-currency documents); the section totals are recomputed
 * from the lines written so a hand-edited document stays consistent.
 */
class D406XmlGenerator implements DeclarationXmlGeneratorInterface
{
    public const NAMESPACE = 'mfp:anaf:dgti:d406:declaratie:v1';

    private \DOMDocument $dom;

    public function supportsType(string $type): bool
    {
        return $type === 'd406';
    }

    public function generate(TaxDeclaration $declaration): string
    {
        $data = $declaration->getData() ?? [];
        $company = $declaration->getCompany();

        $this->dom = new \DOMDocument('1.0', 'UTF-8');
        $this->dom->formatOutput = true;

        $root = $this->dom->createElement('AuditFile');
        $root->setAttribute('xmlns', self::NAMESPACE);
        $root->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $this->dom->appendChild($root);

        $header = $data['header'] ?? [];
        $selection = $header['selection'] ?? [];
        $period = $data['period'] ?? [];

        // ── Header ────────────────────────────────────────────────────────────
        $h = $this->child($root, 'Header');
        $this->text($h, 'AuditFileVersion', $header['auditFileVersion'] ?? D406Populator::AUDIT_FILE_VERSION);
        $this->text($h, 'AuditFileCountry', 'RO');
        $this->text($h, 'AuditFileDateCreated', $header['dateCreated'] ?? date('Y-m-d'));
        $this->text($h, 'SoftwareCompanyName', $header['softwareCompanyName'] ?? D406Populator::SOFTWARE_COMPANY);
        $this->text($h, 'SoftwareID', $header['softwareId'] ?? D406Populator::SOFTWARE_ID);
        $this->text($h, 'SoftwareVersion', $header['softwareVersion'] ?? D406Populator::readVersion());

        $c = $this->child($h, 'Company');
        $this->text($c, 'RegistrationNumber', $header['registrationNumber'] ?? (string) $company->getCif());
        $this->text($c, 'Name', $header['name'] ?? (string) $company->getName());
        $address = $this->child($c, 'Address');
        $this->text($address, 'StreetName', $header['street'] ?? null);
        $this->text($address, 'City', $header['city'] ?? (string) $company->getCity());
        $this->text($address, 'Region', $header['region'] ?? null);
        $this->text($address, 'Country', $header['country'] ?? 'RO');
        $this->text($address, 'AddressType', 'StreetAddress');
        if (!empty($header['contact'])) {
            $contact = $this->child($c, 'Contact');
            $person = $this->child($contact, 'ContactPerson');
            $this->text($person, 'FirstName', $header['contact']['firstName'] ?? null);
            $this->text($person, 'LastName', $header['contact']['lastName'] ?? null);
            $this->text($contact, 'Telephone', $header['contact']['phone'] ?? null);
            $this->text($contact, 'Email', $header['contact']['email'] ?? null);
        }
        if (!empty($header['taxRegistration'])) {
            $tr = $this->child($c, 'TaxRegistration');
            $this->text($tr, 'TaxRegistrationNumber', $header['taxRegistration']['number'] ?? null);
            $this->text($tr, 'TaxType', $header['taxRegistration']['type'] ?? null);
            $this->text($tr, 'TaxNumber', $header['taxRegistration']['taxNumber'] ?? null);
        }
        foreach ($header['bankAccounts'] ?? [] as $account) {
            // the validator treats IBANNumber / BankAccountNumber / BankAccountName as one
            // choice group, so only the IBAN is written (the bank name stays in `data`)
            $bank = $this->child($c, 'BankAccount');
            $this->text($bank, 'IBANNumber', $account['iban'] ?? null);
        }

        $this->text($h, 'DefaultCurrencyCode', $header['currency'] ?? 'RON');
        // SelectionCriteria is a choice: either the two selection dates or the four period
        // elements. The reporting period (month / quarter and year) is what the validator reads.
        $sc = $this->child($h, 'SelectionCriteria');
        $this->text($sc, 'PeriodStart', $selection['periodStart'] ?? $period['startMonth'] ?? null);
        $this->text($sc, 'PeriodStartYear', $selection['periodStartYear'] ?? $period['startYear'] ?? null);
        $this->text($sc, 'PeriodEnd', $selection['periodEnd'] ?? $period['endMonth'] ?? null);
        $this->text($sc, 'PeriodEndYear', $selection['periodEndYear'] ?? $period['endYear'] ?? null);
        $this->text($h, 'HeaderComment', $header['headerComment'] ?? ($declaration->getPeriodType() === 'quarterly' ? 'T' : 'L'));
        $this->text($h, 'SegmentIndex', '1');
        $this->text($h, 'TotalSegmentsInsequence', '1');
        $this->text($h, 'TaxAccountingBasis', $header['taxAccountingBasis'] ?? 'A');

        // ── MasterFiles ───────────────────────────────────────────────────────
        $mf = $this->child($root, 'MasterFiles');
        $gla = $this->child($mf, 'GeneralLedgerAccounts');
        foreach ($data['accounts'] ?? [] as $account) {
            $a = $this->child($gla, 'Account');
            $this->text($a, 'AccountID', $account['id']);
            $this->text($a, 'AccountDescription', $account['description'] ?? ('Cont ' . $account['id']));
            $this->text($a, 'AccountType', $account['type'] ?? 'Bifunctional');
            $this->balances($a, $account);
        }

        $customers = $this->child($mf, 'Customers');
        foreach ($data['customers'] ?? [] as $partner) {
            $this->partner($customers, 'Customer', 'CustomerID', $partner);
        }
        $suppliers = $this->child($mf, 'Suppliers');
        foreach ($data['suppliers'] ?? [] as $partner) {
            $this->partner($suppliers, 'Supplier', 'SupplierID', $partner);
        }

        $taxTable = $this->child($mf, 'TaxTable');
        $byType = [];
        foreach ($data['taxCodes'] ?? [] as $code) {
            $byType[(string) ($code['taxType'] ?? SaftTaxCodes::TAX_TYPE_VAT)][] = $code;
        }
        krsort($byType); // 300 (TVA) first, then 000
        foreach ($byType as $taxType => $codes) {
            $entry = $this->child($taxTable, 'TaxTableEntry');
            $this->text($entry, 'TaxType', $taxType);
            $this->text($entry, 'Description', $taxType === SaftTaxCodes::TAX_TYPE_VAT ? 'Taxa pe valoarea adaugata' : 'Fara impozit / taxa');
            foreach ($codes as $code) {
                $details = $this->child($entry, 'TaxCodeDetails');
                $this->text($details, 'TaxCode', $code['code']);
                $this->text($details, 'Description', $code['description'] ?? null);
                $this->text($details, 'TaxPercentage', $this->decimal($code['rate'] ?? 0));
                // BaseRate is mandatory and expressed as a fraction of 1 (21 % → 0.2100)
                $this->text($details, 'BaseRate', bcdiv((string) ($code['rate'] ?? 0), '100', 4));
                $this->text($details, 'Country', 'RO');
            }
        }

        $uomTable = $this->child($mf, 'UOMTable');
        foreach ($data['uoms'] ?? [] as $uom) {
            $entry = $this->child($uomTable, 'UOMTableEntry');
            $this->text($entry, 'UnitOfMeasure', $uom['code']);
            $this->text($entry, 'Description', $uom['description'] ?? $uom['code']);
        }
        $this->child($mf, 'AnalysisTypeTable');
        $this->child($mf, 'MovementTypeTable');

        $products = $this->child($mf, 'Products');
        foreach ($data['products'] ?? [] as $product) {
            $p = $this->child($products, 'Product');
            $this->text($p, 'ProductCode', $product['code']);
            $this->text($p, 'GoodsServicesID', $product['goodsServicesId'] ?? '01');
            $this->text($p, 'Description', $product['description'] ?? $product['code']);
            $this->text($p, 'ProductCommodityCode', $product['commodityCode'] ?? '0');
            $this->text($p, 'UOMBase', $product['uom'] ?? 'H87');
            $this->text($p, 'UOMStandard', $product['uom'] ?? 'H87');
            $this->text($p, 'UOMToUOMBaseConversionFactor', '1');
        }
        $this->child($mf, 'Owners');
        $this->child($mf, 'Assets');

        // ── GeneralLedgerEntries ──────────────────────────────────────────────
        $gl = $this->child($root, 'GeneralLedgerEntries');
        $entries = 0;
        $debit = '0.00';
        $credit = '0.00';
        $journalNodes = [];
        foreach ($data['journals'] ?? [] as $journal) {
            $transactions = $journal['transactions'] ?? [];
            if ($transactions === []) {
                continue;
            }
            $j = $this->dom->createElement('Journal');
            $this->text($j, 'JournalID', $journal['id']);
            $this->text($j, 'Description', $journal['description'] ?? $journal['id']);
            $this->text($j, 'Type', $journal['type'] ?? 'GL');
            foreach ($transactions as $transaction) {
                $entries++;
                $t = $this->child($j, 'Transaction');
                $this->text($t, 'TransactionID', $transaction['id']);
                $this->text($t, 'Period', $transaction['period']);
                $this->text($t, 'PeriodYear', $transaction['periodYear']);
                $this->text($t, 'TransactionDate', $transaction['date']);
                $this->text($t, 'Description', $transaction['description']);
                $this->text($t, 'SystemEntryDate', $transaction['postingDate'] ?? $transaction['date']);
                $this->text($t, 'GLPostingDate', $transaction['postingDate'] ?? $transaction['date']);
                $this->text($t, 'CustomerID', $transaction['customerId'] ?? '0');
                $this->text($t, 'SupplierID', $transaction['supplierId'] ?? '0');
                foreach ($transaction['lines'] ?? [] as $line) {
                    $l = $this->child($t, 'TransactionLine');
                    $this->text($l, 'RecordID', $line['recordId']);
                    $this->text($l, 'AccountID', $line['accountId']);
                    $this->text($l, 'SourceDocumentID', $line['sourceDocumentId'] ?? null);
                    $this->text($l, 'CustomerID', $line['customerId'] ?? '0');
                    $this->text($l, 'SupplierID', $line['supplierId'] ?? '0');
                    $this->text($l, 'Description', $line['description']);
                    $this->amount($l, $line['side'] === 'D' ? 'DebitAmount' : 'CreditAmount', $line);
                    if ($line['side'] === 'D') {
                        $debit = bcadd($debit, $line['amount'], 2);
                    } else {
                        $credit = bcadd($credit, $line['amount'], 2);
                    }
                    $this->taxInformation($l, $line, $line['currency'] ?? 'RON', $line['exchangeRate'] ?? null);
                }
            }
            $journalNodes[] = $j;
        }
        $this->text($gl, 'NumberOfEntries', (string) $entries);
        $this->text($gl, 'TotalDebit', $debit);
        $this->text($gl, 'TotalCredit', $credit);
        foreach ($journalNodes as $j) {
            $gl->appendChild($j);
        }

        // ── SourceDocuments ───────────────────────────────────────────────────
        $sd = $this->child($root, 'SourceDocuments');
        $this->invoices($sd, 'SalesInvoices', $data['salesInvoices'] ?? [], true);
        $this->invoices($sd, 'PurchaseInvoices', $data['purchaseInvoices'] ?? [], false);
        $this->payments($sd, $data['payments'] ?? []);
        // MovementOfGoods (stock) and AssetTransactions carry data only in the on-request and
        // the annual file; in a monthly / quarterly one the section stays empty.
        $this->child($sd, 'MovementOfGoods');

        return $this->dom->saveXML();
    }

    /** @param list<array<string, mixed>> $documents */
    private function invoices(\DOMElement $parent, string $section, array $documents, bool $sales): void
    {
        $node = $this->child($parent, $section);
        $debit = '0.00';
        $credit = '0.00';
        $invoiceNodes = [];
        foreach ($documents as $document) {
            $inv = $this->dom->createElement('Invoice');
            $this->text($inv, 'InvoiceNo', $document['invoiceNo']);
            $info = $this->child($inv, $sales ? 'CustomerInfo' : 'SupplierInfo');
            $this->text($info, $sales ? 'CustomerID' : 'SupplierID', $document['partnerId']);
            $billing = $this->child($info, 'BillingAddress');
            $this->text($billing, 'City', $document['billing']['city'] ?? '-');
            $this->text($billing, 'Country', $document['billing']['country'] ?? 'RO');
            $this->text($billing, 'AddressType', 'BillingAddress');
            $this->text($inv, 'AccountID', $document['accountId']);
            $this->text($inv, 'Period', $document['period'] ?? null);
            $this->text($inv, 'PeriodYear', $document['periodYear'] ?? null);
            $this->text($inv, 'InvoiceDate', $document['date']);
            $this->text($inv, 'InvoiceType', $document['type'] ?? '380');
            $this->text($inv, 'SelfBillingIndicator', $document['selfBilling'] ?? '0');
            $this->text($inv, 'GLPostingDate', $document['postingDate'] ?? $document['date']);
            $this->text($inv, 'TransactionID', $document['transactionId'] ?? null);
            foreach ($document['lines'] ?? [] as $line) {
                $l = $this->child($inv, 'InvoiceLine');
                $this->text($l, 'LineNumber', $line['lineNumber']);
                $this->text($l, 'AccountID', $line['accountId']);
                $this->text($l, 'ProductCode', $line['productCode'] ?? null);
                $this->text($l, 'ProductDescription', $line['description']);
                $this->text($l, 'Quantity', $line['quantity'] ?? '1');
                $this->text($l, 'InvoiceUOM', $line['uom'] ?? 'H87');
                $this->text($l, 'UnitPrice', $line['unitPrice'] ?? '0.00');
                $this->text($l, 'TaxPointDate', $line['taxPointDate'] ?? $document['date']);
                $this->text($l, 'Description', $line['description']);
                $this->amount($l, 'InvoiceLineAmount', $line);
                $this->text($l, 'DebitCreditIndicator', $line['side']);
                $this->taxInformation($l, $line, $line['currency'] ?? 'RON', $line['exchangeRate'] ?? null);
                if ($line['side'] === 'D') {
                    $debit = bcadd($debit, $line['amount'], 2);
                } else {
                    $credit = bcadd($credit, $line['amount'], 2);
                }
            }
            $totals = $this->child($inv, 'InvoiceDocumentTotals');
            foreach ($document['taxTotals'] ?? [] as $tax) {
                $ti = $this->child($totals, 'TaxInformationTotals');
                $this->text($ti, 'TaxType', $tax['taxType']);
                $this->text($ti, 'TaxCode', $tax['taxCode']);
                $this->text($ti, 'TaxPercentage', $this->decimal($tax['taxPercentage'] ?? 0));
                $this->text($ti, 'TaxBase', $tax['taxBase'] ?? null);
                $this->amountNode($ti, 'TaxAmount', $tax['taxAmount'] ?? '0.00', $document['currency'] ?? 'RON', $tax['taxAmountCurrency'] ?? null, $document['exchangeRate'] ?? null);
            }
            $this->text($totals, 'NetTotal', $document['netTotal'] ?? '0.00');
            $this->text($totals, 'GrossTotal', $document['grossTotal'] ?? '0.00');
            $invoiceNodes[] = $inv;
        }
        $this->text($node, 'NumberOfEntries', (string) count($documents));
        $this->text($node, 'TotalDebit', $debit);
        $this->text($node, 'TotalCredit', $credit);
        foreach ($invoiceNodes as $inv) {
            $node->appendChild($inv);
        }
    }

    /** @param list<array<string, mixed>> $documents */
    private function payments(\DOMElement $parent, array $documents): void
    {
        $node = $this->child($parent, 'Payments');
        $debit = '0.00';
        $credit = '0.00';
        $paymentNodes = [];
        foreach ($documents as $document) {
            $p = $this->dom->createElement('Payment');
            $this->text($p, 'PaymentRefNo', $document['refNo']);
            $this->text($p, 'Period', $document['period'] ?? null);
            $this->text($p, 'PeriodYear', $document['periodYear'] ?? null);
            $this->text($p, 'TransactionID', $document['transactionId'] ?? null);
            $this->text($p, 'TransactionDate', $document['date']);
            $this->text($p, 'PaymentMethod', $document['method'] ?? '03');
            $this->text($p, 'Description', $document['description']);
            foreach ($document['lines'] ?? [] as $line) {
                $l = $this->child($p, 'PaymentLine');
                $this->text($l, 'LineNumber', $line['lineNumber'] ?? '1');
                $this->text($l, 'SourceDocumentID', $line['sourceDocumentId'] ?? null);
                $this->text($l, 'AccountID', $line['accountId']);
                $this->text($l, 'CustomerID', $line['customerId'] ?? '0');
                $this->text($l, 'SupplierID', $line['supplierId'] ?? '0');
                $this->text($l, 'Description', $line['description'] ?? null);
                $this->text($l, 'DebitCreditIndicator', $line['side']);
                $this->amount($l, 'PaymentLineAmount', $line);
                $this->taxInformation($l, $line, $line['currency'] ?? 'RON', $line['exchangeRate'] ?? null);
                if ($line['side'] === 'D') {
                    $debit = bcadd($debit, $line['amount'], 2);
                } else {
                    $credit = bcadd($credit, $line['amount'], 2);
                }
            }
            $settlement = $this->child($p, 'PaymentSettlement');
            $this->amountNode($settlement, 'SettlementAmount', $this->absolute($document['amount'] ?? '0.00'), $document['currency'] ?? 'RON', null, $document['exchangeRate'] ?? null);
            $this->text($settlement, 'SettlementDate', $document['date']);
            $this->text($settlement, 'PaymentMechanism', $document['mechanism'] ?? '42');
            $totals = $this->child($p, 'PaymentDocumentTotals');
            $this->text($totals, 'GrossTotal', $document['grossTotal'] ?? $document['amount'] ?? '0.00');
            $paymentNodes[] = $p;
        }
        $this->text($node, 'NumberOfEntries', (string) count($documents));
        $this->text($node, 'TotalDebit', $debit);
        $this->text($node, 'TotalCredit', $credit);
        foreach ($paymentNodes as $p) {
            $node->appendChild($p);
        }
    }

    /** @param array<string, mixed> $partner */
    private function partner(\DOMElement $parent, string $element, string $idElement, array $partner): void
    {
        $node = $this->child($parent, $element);
        $cs = $this->child($node, 'CompanyStructure');
        $this->text($cs, 'RegistrationNumber', $partner['registrationNumber'] ?? $partner['id']);
        $this->text($cs, 'Name', $partner['name'] ?? $partner['id']);
        $address = $this->child($cs, 'Address');
        $this->text($address, 'City', $partner['city'] ?? '-');
        $this->text($address, 'Country', $partner['country'] ?? 'RO');
        $this->text($address, 'AddressType', 'StreetAddress');
        $this->text($node, $idElement, $partner['id']);
        $this->text($node, 'AccountID', $partner['accountId']);
        $this->balances($node, $partner);
    }

    /** @param array<string, mixed> $row */
    private function balances(\DOMElement $node, array $row): void
    {
        if (($row['openingCredit'] ?? null) !== null && ($row['openingDebit'] ?? null) === null) {
            $this->text($node, 'OpeningCreditBalance', $row['openingCredit']);
        } else {
            $this->text($node, 'OpeningDebitBalance', $row['openingDebit'] ?? '0.00');
        }
        if (($row['closingCredit'] ?? null) !== null && ($row['closingDebit'] ?? null) === null) {
            $this->text($node, 'ClosingCreditBalance', $row['closingCredit']);
        } else {
            $this->text($node, 'ClosingDebitBalance', $row['closingDebit'] ?? '0.00');
        }
    }

    /** @param array<string, mixed> $line */
    private function amount(\DOMElement $parent, string $element, array $line): void
    {
        $this->amountNode($parent, $element, $line['amount'] ?? '0.00', $line['currency'] ?? 'RON', $line['currencyAmount'] ?? null, $line['exchangeRate'] ?? null);
    }

    private function amountNode(\DOMElement $parent, string $element, string $amount, string $currency, ?string $currencyAmount, ?string $exchangeRate): void
    {
        $node = $this->child($parent, $element);
        $this->text($node, 'Amount', $amount);
        $this->text($node, 'CurrencyCode', $currency ?: 'RON');
        if ($currency === 'RON' || $currency === '' || $exchangeRate === null) {
            $this->text($node, 'CurrencyAmount', $amount);
        } else {
            $this->text($node, 'CurrencyAmount', $currencyAmount ?? bcdiv($amount, $exchangeRate, 2));
            $this->text($node, 'ExchangeRate', $exchangeRate);
        }
    }

    /** @param array<string, mixed> $line */
    private function taxInformation(\DOMElement $parent, array $line, string $currency, ?string $exchangeRate): void
    {
        $ti = $this->child($parent, 'TaxInformation');
        $this->text($ti, 'TaxType', $line['taxType'] ?? SaftTaxCodes::TAX_TYPE_NONE);
        $this->text($ti, 'TaxCode', $line['taxCode'] ?? SaftTaxCodes::TAX_CODE_NONE);
        if (($line['taxType'] ?? SaftTaxCodes::TAX_TYPE_NONE) !== SaftTaxCodes::TAX_TYPE_NONE) {
            $this->text($ti, 'TaxPercentage', $this->decimal($line['taxPercentage'] ?? 0));
            $this->text($ti, 'TaxBase', isset($line['taxBase']) ? $this->absolute((string) $line['taxBase']) : null);
        }
        $taxAmount = $this->absolute((string) ($line['taxAmount'] ?? '0.00'));
        $taxCurrency = isset($line['taxAmountCurrency']) ? $this->absolute((string) $line['taxAmountCurrency']) : null;
        $this->amountNode($ti, 'TaxAmount', $taxAmount, $currency, $taxCurrency, $exchangeRate);
    }

    private function child(\DOMElement $parent, string $name): \DOMElement
    {
        $node = $this->dom->createElement($name);
        $parent->appendChild($node);

        return $node;
    }

    /** Appends a text element; nothing is written for null / '' (the optional elements). */
    private function text(\DOMElement $parent, string $name, string|int|float|null $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $node = $this->dom->createElement($name);
        $node->appendChild($this->dom->createTextNode((string) $value));
        $parent->appendChild($node);
    }

    private function decimal(string|int|float $value): string
    {
        return (string) (is_numeric($value) ? (0 + $value) : 0);
    }

    private function absolute(string $amount): string
    {
        return is_numeric($amount) && bccomp($amount, '0', 2) < 0 ? bcmul($amount, '-1', 2) : $amount;
    }
}
