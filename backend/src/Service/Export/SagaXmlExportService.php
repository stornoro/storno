<?php

namespace App\Service\Export;

use App\Entity\Client;
use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\Payment;
use App\Entity\Product;
use App\Entity\Supplier;

class SagaXmlExportService
{
    public function __construct(
        private readonly \App\Service\ExchangeRateService $exchangeRateService,
    ) {}

    /**
     * SAGA Clienti: root <Clienti>, children <Linie>
     *
     * @param Client[] $clients
     */
    public function generateClientsXml(array $clients): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('Clienti');
        $dom->appendChild($root);

        foreach ($clients as $index => $client) {
            $node = $dom->createElement('Linie');

            $this->addElement($dom, $node, 'Cod', $client->getClientCode() ?: (string) ($index + 1));
            $this->addElement($dom, $node, 'Denumire', $client->getName() ?? '');
            $this->addElement($dom, $node, 'Cod_fiscal', $client->getVatCode() ?: ($client->getCui() ?? ''));
            $this->addElement($dom, $node, 'Reg_com', $client->getRegistrationNumber() ?? '');
            $this->addElement($dom, $node, 'Tara', $client->getCountry());
            $this->addElement($dom, $node, 'Judet', $client->getCountry() === 'RO' ? ($client->getCounty() ?? '') : '');
            $this->addElement($dom, $node, 'Localitate', $client->getCity() ?? '');
            $this->addElement($dom, $node, 'Adresa', $client->getAddress() ?? '');
            $this->addElement($dom, $node, 'Cont_banca', $client->getBankAccount() ?? '');
            $this->addElement($dom, $node, 'Banca', $client->getBankName() ?? '');
            $this->addElement($dom, $node, 'Tel', $client->getPhone() ?? '');
            $this->addElement($dom, $node, 'Email', $client->getEmail() ?? '');
            $this->addElement($dom, $node, 'Discount', '');
            $this->addElement($dom, $node, 'Informatii', $client->getNotes() ?? '');
            $this->addElement($dom, $node, 'Guid_cod', $client->getId()?->toRfc4122() ?? '');

            $root->appendChild($node);
        }

        return $dom->saveXML();
    }

    /**
     * SAGA Furnizori: root <Furnizori>, children <Linie>
     *
     * @param Supplier[] $suppliers
     */
    public function generateSuppliersXml(array $suppliers): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('Furnizori');
        $dom->appendChild($root);

        foreach ($suppliers as $index => $supplier) {
            $node = $dom->createElement('Linie');

            $this->addElement($dom, $node, 'Cod', (string) ($index + 1));
            $this->addElement($dom, $node, 'Denumire', $supplier->getName() ?? '');
            $this->addElement($dom, $node, 'Cod_fiscal', $supplier->getVatCode() ?: ($supplier->getCif() ?? ''));
            $this->addElement($dom, $node, 'Tara', $supplier->getCountry());
            $this->addElement($dom, $node, 'Localitate', $supplier->getCity() ?? '');
            $this->addElement($dom, $node, 'Adresa', $supplier->getAddress() ?? '');
            $this->addElement($dom, $node, 'Cont_banca', $supplier->getBankAccount() ?? '');
            $this->addElement($dom, $node, 'Banca', $supplier->getBankName() ?? '');
            $this->addElement($dom, $node, 'Tel', $supplier->getPhone() ?? '');
            $this->addElement($dom, $node, 'Email', $supplier->getEmail() ?? '');
            $this->addElement($dom, $node, 'Informatii', $supplier->getNotes() ?? '');
            $this->addElement($dom, $node, 'Guid_cod', $supplier->getId()?->toRfc4122() ?? '');

            $root->appendChild($node);
        }

        return $dom->saveXML();
    }

    /**
     * SAGA Articole: root <Articole>, children <Linie>
     *
     * @param Product[] $products
     */
    public function generateProductsXml(array $products): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('Articole');
        $dom->appendChild($root);

        foreach ($products as $product) {
            $node = $dom->createElement('Linie');

            $this->addElement($dom, $node, 'Cod', $product->getCode() ?? '');
            $this->addElement($dom, $node, 'Denumire', $product->getName() ?? '');
            $this->addElement($dom, $node, 'Cod_NC', $product->getNcCode() ?? '');
            $this->addElement($dom, $node, 'Cod_CPV', $product->getCpvCode() ?? '');
            $this->addElement($dom, $node, 'UM', $product->getUnitOfMeasure());
            $this->addElement($dom, $node, 'Tip', $product->isService() ? 'Serviciu' : 'Marfa');
            $this->addElement($dom, $node, 'TVA', $product->getVatRate());
            $this->addElement($dom, $node, 'Pret', $product->getDefaultPrice());
            // Pret_TVA = price with VAT included
            $vatMultiplier = bcadd('1', bcdiv($product->getVatRate(), '100', 6), 6);
            $pretTva = bcmul($product->getDefaultPrice(), $vatMultiplier, 2);
            $this->addElement($dom, $node, 'Pret_TVA', $pretTva);
            $this->addElement($dom, $node, 'Cod_bare', '');
            $this->addElement($dom, $node, 'Informatii', $product->getDescription() ?? '');
            $this->addElement($dom, $node, 'Guid_cod', $product->getId()?->toRfc4122() ?? '');

            $root->appendChild($node);
        }

        return $dom->saveXML();
    }

    /**
     * SAGA Facturi: root <Facturi>, children <Factura>.
     *
     * Streamed with XMLWriter (constant memory, no DOM tree) — large invoice
     * sets used to build a full DOMDocument before serialising, which was the
     * bottleneck of the accounting export.
     *
     * @param Invoice[] $invoices
     */
    public function generateInvoicesXml(array $invoices, Company $company, bool $includeDiscount = false): string
    {
        $w = new \XMLWriter();
        $w->openMemory();
        $w->setIndent(true);
        $w->setIndentString('  ');
        $w->startDocument('1.0', 'UTF-8');

        $w->startElement('Facturi');

        foreach ($invoices as $invoice) {
            $w->startElement('Factura');

            // ── Antet ──
            $w->startElement('Antet');

            // Furnizor (company)
            $this->writeEl($w, 'FurnizorNume', $company->getName() ?? '');
            $this->writeEl($w, 'FurnizorCIF', (string) $company->getCif());
            $this->writeEl($w, 'FurnizorNrRegCom', $company->getRegistrationNumber() ?? '');
            $this->writeEl($w, 'FurnizorCapital', '');
            $this->writeEl($w, 'FurnizorTara', $company->getCountry() ?? 'RO');
            $this->writeEl($w, 'FurnizorLocalitate', $company->getCity() ?? '');
            $this->writeEl($w, 'FurnizorJudet', $company->getState() ?? '');
            $this->writeEl($w, 'FurnizorAdresa', $company->getAddress() ?? '');
            $this->writeEl($w, 'FurnizorTelefon', $company->getPhone() ?? '');
            $this->writeEl($w, 'FurnizorMail', $company->getEmail() ?? '');
            $this->writeEl($w, 'FurnizorBanca', $company->getBankName() ?? '');
            $this->writeEl($w, 'FurnizorIBAN', $company->getBankAccount() ?? '');
            $this->writeEl($w, 'FurnizorInformatiiSuplimentare', '');

            // Client — read from buyer snapshot (frozen at issue time) to
            // avoid showing the wrong details if the linked client is later
            // edited or re-linked.
            $buyer = $this->resolveBuyer($invoice);
            $this->writeEl($w, 'ClientNume', $invoice->getReceiverName() ?? ($buyer['name'] ?? ''));
            $this->writeEl($w, 'ClientInformatiiSuplimentare', '');
            $this->writeEl($w, 'ClientCIF', $invoice->getReceiverCif() ?? ($buyer['cui'] ?? ''));
            $this->writeEl($w, 'ClientNrRegCom', $buyer['registrationNumber'] ?? '');
            $clientCountry = $buyer['country'] ?? 'RO';
            $this->writeEl($w, 'ClientJudet', $clientCountry === 'RO' ? ($buyer['county'] ?? '') : '');
            $this->writeEl($w, 'ClientTara', $clientCountry);
            $this->writeEl($w, 'ClientLocalitate', $buyer['city'] ?? '');
            $this->writeEl($w, 'ClientAdresa', $buyer['address'] ?? '');
            $this->writeEl($w, 'ClientBanca', $buyer['bankName'] ?? '');
            $this->writeEl($w, 'ClientIBAN', $buyer['bankAccount'] ?? '');
            $this->writeEl($w, 'ClientTelefon', $buyer['phone'] ?? '');
            $this->writeEl($w, 'ClientMail', $buyer['email'] ?? '');

            // Factura metadata
            $this->writeEl($w, 'FacturaNumar', $invoice->getNumber() ?? '');
            $this->writeEl($w, 'FacturaData', $invoice->getIssueDate()?->format('d.m.Y') ?? '');
            $this->writeEl($w, 'FacturaScadenta', $invoice->getDueDate()?->format('d.m.Y') ?? '');
            $this->writeEl($w, 'FacturaTaxareInversa', $this->isReverseCharge($invoice) ? 'Da' : 'Nu');
            $this->writeEl($w, 'FacturaTVAIncasare', $invoice->isTvaLaIncasare() ? 'Da' : 'Nu');
            $this->writeEl($w, 'FacturaTip', $this->getSagaInvoiceType($invoice));
            $this->writeEl($w, 'FacturaInformatiiSuplimentare', '');
            $this->writeEl($w, 'FacturaMoneda', $invoice->getCurrency());
            $this->writeEl($w, 'FacturaGreutate', '0.000');

            // Discount at invoice level (optional)
            if ($includeDiscount && bccomp($invoice->getDiscount(), '0', 2) > 0) {
                $this->writeEl($w, 'FacturaDiscount', $invoice->getDiscount());
            }

            $w->endElement(); // Antet

            // ── Detalii > Continut ──
            $w->startElement('Detalii');
            $w->startElement('Continut');

            $lineIndex = 0;
            foreach ($invoice->getLines() as $line) {
                $lineIndex++;
                $w->startElement('Linie');

                $this->writeEl($w, 'LinieNrCrt', (string) $lineIndex);
                $this->writeEl($w, 'Descriere', $line->getDescription() ?? '');
                $this->writeEl($w, 'CodArticolFurnizor', $line->getProductCode() ?? '');
                $this->writeEl($w, 'CodArticolClient', $line->getBuyerItemIdentification() ?? '');
                $this->writeEl($w, 'CodBare', $line->getStandardItemIdentification() ?? '');
                $this->writeEl($w, 'InformatiiSuplimentare', $line->getLineNote() ?? '');
                $this->writeEl($w, 'UM', $line->getUnitOfMeasure());
                $this->writeEl($w, 'Cantitate', $line->getQuantity());
                $this->writeEl($w, 'Pret', $line->getUnitPrice());
                $this->writeEl($w, 'Valoare', $line->getLineTotal());
                $this->writeEl($w, 'ProcTVA', $line->getVatRate());
                $this->writeEl($w, 'TVA', $line->getVatAmount());

                $w->endElement(); // Linie
            }

            $w->endElement(); // Continut
            $w->endElement(); // Detalii

            // FacturaID at <Factura> level, after </Detalii>
            $this->writeEl($w, 'FacturaID', preg_replace('/[^0-9]/', '', $invoice->getNumber() ?? ''));

            $w->endElement(); // Factura
        }

        $w->endElement(); // Facturi
        $w->endDocument();

        return $w->outputMemory();
    }

    /**
     * SAGA Incasari (receipts on outgoing invoices): root <Incasari>, children <Linie>
     *
     * @param Payment[] $payments
     * @param array     $accountMap  Optional overrides: ['cash'=>'...','bank_transfer'=>'...','card'=>'...']
     */
    public function generateReceiptsXml(array $payments, array $accountMap = []): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('Incasari');
        $dom->appendChild($root);

        foreach ($payments as $payment) {
            $node = $dom->createElement('Linie');
            $invoice = $payment->getInvoice();
            $invoiceNumber = $invoice?->getNumber() ?? '';
            $paymentDate = $payment->getPaymentDate()?->format('d.m.Y') ?? '';
            $explicatie = $payment->getNotes()
                ?: trim(sprintf('contravaloarea facturii %s%s', $invoiceNumber, $paymentDate !== '' ? ' din data de ' . $paymentDate : ''));

            $method = $payment->getPaymentMethod();
            $this->addElement($dom, $node, 'TipDocument', $this->mapPaymentMethodToTipDocument($method));
            $this->addElement($dom, $node, 'Data', $paymentDate);
            $this->addElement($dom, $node, 'Numar', $payment->getReference() ?: '-');
            $this->addElement($dom, $node, 'Suma', $payment->getAmount());
            $this->addElement($dom, $node, 'Cont', $this->mapPaymentMethodToAccount($method, $accountMap));
            $this->addElement($dom, $node, 'Explicatie', $explicatie);
            $this->addElement($dom, $node, 'FacturaNumar', $invoiceNumber);
            $this->addElement($dom, $node, 'FacturaID', preg_replace('/[^0-9]/', '', $invoiceNumber));
            $this->addElement($dom, $node, 'CodFiscal', $this->codFiscalForReceipt($method, $invoice?->getReceiverCif()));

            $root->appendChild($node);
        }

        return $dom->saveXML();
    }

    /**
     * SAGA Plati (payments on incoming invoices): root <Plati>, children <Linie>
     *
     * @param Payment[] $payments
     * @param array     $accountMap  Optional overrides: ['cash'=>'...','bank_transfer'=>'...','card'=>'...']
     */
    public function generatePaymentsXml(array $payments, array $accountMap = []): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('Plati');
        $dom->appendChild($root);

        foreach ($payments as $payment) {
            $node = $dom->createElement('Linie');
            $invoice = $payment->getInvoice();
            $invoiceNumber = $invoice?->getNumber() ?? '';
            $paymentDate = $payment->getPaymentDate()?->format('d.m.Y') ?? '';
            $explicatie = $payment->getNotes()
                ?: trim(sprintf('contravaloarea facturii %s%s', $invoiceNumber, $paymentDate !== '' ? ' din data de ' . $paymentDate : ''));

            $method = $payment->getPaymentMethod();
            $this->addElement($dom, $node, 'TipDocument', $this->mapPaymentMethodToTipDocument($method));
            $this->addElement($dom, $node, 'Data', $paymentDate);
            $this->addElement($dom, $node, 'Numar', $payment->getReference() ?: '-');
            $this->addElement($dom, $node, 'Suma', $payment->getAmount());
            $this->addElement($dom, $node, 'Cont', $this->mapPaymentMethodToAccount($method, $accountMap));
            $this->addElement($dom, $node, 'Explicatie', $explicatie);
            $this->addElement($dom, $node, 'FacturaNumar', $invoiceNumber);
            $this->addElement($dom, $node, 'FacturaID', preg_replace('/[^0-9]/', '', $invoiceNumber));
            $this->addElement($dom, $node, 'CodFiscal', $this->codFiscalForReceipt($method, $invoice?->getSenderCif()));

            $root->appendChild($node);
        }

        return $dom->saveXML();
    }

    /**
     * SAGA Curs BNR: root <CursBNR>, children <Linie>
     */
    public function generateBnrRatesXml(): string
    {
        $data = $this->exchangeRateService->getRates();

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('CursBNR');
        $dom->appendChild($root);

        foreach ($data['rates'] as $currency => $info) {
            $node = $dom->createElement('Linie');
            $this->addElement($dom, $node, 'Data', date('d.m.Y'));
            $this->addElement($dom, $node, 'Moneda', $currency);
            $rate = $info['value'] / $info['multiplier'];
            $this->addElement($dom, $node, 'Curs', number_format($rate, 4, '.', ''));
            $root->appendChild($node);
        }

        return $dom->saveXML();
    }

    /**
     * SAGA Conturi Clienti: root <ConturiClienti>, children <Linie>
     *
     * @param Client[] $clients
     */
    public function generateClientAccountsXml(array $clients, string $account = '4111'): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('ConturiClienti');
        $dom->appendChild($root);

        foreach ($clients as $client) {
            $cif = $client->getVatCode() ?: ($client->getCui() ?? '');
            if ($cif === '') {
                continue;
            }

            $node = $dom->createElement('Linie');
            $this->addElement($dom, $node, 'CodFiscal', $cif);
            $this->addElement($dom, $node, 'Cont', $account);
            $root->appendChild($node);
        }

        return $dom->saveXML();
    }

    /**
     * SAGA Conturi Furnizori: root <ConturiFurnizori>, children <Linie>
     *
     * @param Supplier[] $suppliers
     */
    public function generateSupplierAccountsXml(array $suppliers, string $account = '4011'): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('ConturiFurnizori');
        $dom->appendChild($root);

        foreach ($suppliers as $supplier) {
            $cif = $supplier->getVatCode() ?: ($supplier->getCif() ?? '');
            if ($cif === '') {
                continue;
            }

            $node = $dom->createElement('Linie');
            $this->addElement($dom, $node, 'CodFiscal', $cif);
            $this->addElement($dom, $node, 'Cont', $account);
            $root->appendChild($node);
        }

        return $dom->saveXML();
    }

    private function addElement(\DOMDocument $dom, \DOMElement $parent, string $name, string $value): void
    {
        $element = $dom->createElement($name);
        $element->appendChild($dom->createTextNode($value));
        $parent->appendChild($element);
    }

    /**
     * Write a leaf element via XMLWriter, matching DOMDocument byte-for-byte:
     * escape only & < > (a DOM text node leaves quotes raw, unlike
     * XMLWriter::text()), and always emit <Name></Name> for empty values
     * rather than a self-closing <Name/>.
     */
    private function writeEl(\XMLWriter $w, string $name, string $value): void
    {
        $w->startElement($name);
        if ($value === '') {
            $w->text('');
        } else {
            $w->writeRaw(str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $value));
        }
        $w->endElement();
    }

    private function isReverseCharge(Invoice $invoice): bool
    {
        $typeCode = $invoice->getInvoiceTypeCode();

        return $typeCode === '389' || $typeCode === 'reverse_charge';
    }

    /**
     * SAGA invoice type: space = regular, 1 = OSS, A = aviz, T = reverse-tax.
     */
    private function getSagaInvoiceType(Invoice $invoice): string
    {
        if ($this->isReverseCharge($invoice)) {
            return 'T';
        }

        if ($this->isOss($invoice)) {
            return '1';
        }

        return ' ';
    }

    private function isOss(Invoice $invoice): bool
    {
        $company = $invoice->getCompany();
        $buyer = $this->resolveBuyer($invoice);
        $country = $buyer['country'] ?? null;
        $client = $invoice->getClient();

        return $company
            && $company->isOss()
            && $country !== 'RO'
            && $country !== null
            && $client?->isViesValid() !== true;
    }

    /**
     * Returns the buyer details for export. Prefers the invoice's frozen
     * buyer snapshot over the live Client entity so that historical exports
     * keep showing the details at issue time.
     *
     * @return array<string, mixed>
     */
    private function resolveBuyer(Invoice $invoice): array
    {
        $snapshot = $invoice->getBuyerSnapshot();
        if (!empty($snapshot)) {
            return $snapshot;
        }

        $client = $invoice->getClient();
        if ($client === null) {
            return [];
        }

        return [
            'type' => $client->getType(),
            'name' => $client->getName(),
            'cui' => $client->getCui(),
            'cnp' => $client->getCnp(),
            'vatCode' => $client->getVatCode(),
            'isVatPayer' => $client->isVatPayer(),
            'registrationNumber' => $client->getRegistrationNumber(),
            'address' => $client->getAddress(),
            'city' => $client->getCity(),
            'county' => $client->getCounty(),
            'country' => $client->getCountry(),
            'postalCode' => $client->getPostalCode(),
            'email' => $client->getEmail(),
            'phone' => $client->getPhone(),
            'bankName' => $client->getBankName(),
            'bankAccount' => $client->getBankAccount(),
            'clientCode' => $client->getClientCode(),
            'einvoiceIdentifiers' => $client->getEinvoiceIdentifiers(),
        ];
    }

    private function mapPaymentMethodToAccount(string $method, array $accountMap = []): string
    {
        $defaults = [
            'cash' => '5311',
            'bank_transfer' => '5121',
            'card' => '5125.2',
        ];

        $merged = array_merge($defaults, array_filter($accountMap, static fn ($v) => $v !== null && $v !== ''));

        return $merged[$method] ?? $merged['bank_transfer'];
    }

    private function mapPaymentMethodToTipDocument(string $method): string
    {
        return match ($method) {
            'cash' => 'Chitanta',
            'card' => 'Card',
            'bank_transfer' => 'OP',
            default => 'OP',
        };
    }

    /**
     * CodFiscal pollution check for SAGA receipts/payments.
     *
     * SAGA matches partners by CIF, but only recognises Romanian CIFs.
     * For card flows it goes through a merchant aggregator so the partner
     * CIF must stay empty, otherwise the line is rejected.
     */
    private function codFiscalForReceipt(string $method, ?string $cif): string
    {
        if ($method === 'card') {
            return '';
        }

        return $this->sanitizeRoCodFiscal($cif);
    }

    private function sanitizeRoCodFiscal(?string $cif): string
    {
        if ($cif === null) {
            return '';
        }

        $trimmed = trim($cif);
        if ($trimmed === '') {
            return '';
        }

        // Strip RO prefix if present, allow only digits (Romanian CIF/CUI format).
        $normalized = preg_replace('/^RO/i', '', $trimmed) ?? '';
        if (!preg_match('/^[0-9]{2,10}$/', $normalized)) {
            return '';
        }

        return $normalized;
    }
}
