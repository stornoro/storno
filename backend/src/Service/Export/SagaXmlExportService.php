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
    private const EU_COUNTRY_CODES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES',
        'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT',
        'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

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
        return $this->render('Clienti', $clients, $this->clientLine(...));
    }

    /**
     * @param iterable<Client> $clients
     */
    public function streamClientsXml(iterable $clients): void
    {
        $this->stream('Clienti', $clients, $this->clientLine(...));
    }

    /**
     * SAGA Furnizori: root <Furnizori>, children <Linie>
     *
     * @param Supplier[] $suppliers
     */
    public function generateSuppliersXml(array $suppliers): string
    {
        return $this->render('Furnizori', $suppliers, $this->supplierLineWriter());
    }

    /**
     * @param iterable<Supplier> $suppliers
     */
    public function streamSuppliersXml(iterable $suppliers): void
    {
        $this->stream('Furnizori', $suppliers, $this->supplierLineWriter());
    }

    /**
     * SAGA Articole: root <Articole>, children <Linie>
     *
     * @param Product[] $products
     */
    public function generateProductsXml(array $products): string
    {
        return $this->render('Articole', $products, $this->productLine(...));
    }

    /**
     * @param iterable<Product> $products
     */
    public function streamProductsXml(iterable $products): void
    {
        $this->stream('Articole', $products, $this->productLine(...));
    }

    /**
     * SAGA Facturi: root <Facturi>, children <Factura>.
     *
     * @param Invoice[] $invoices
     */
    public function generateInvoicesXml(array $invoices, Company $company, bool $includeDiscount = false): string
    {
        return $this->render('Facturi', $invoices, $this->invoiceWriter($company, $includeDiscount));
    }

    /**
     * @param iterable<Invoice> $invoices
     */
    public function streamInvoicesXml(iterable $invoices, Company $company, bool $includeDiscount = false): void
    {
        $this->stream('Facturi', $invoices, $this->invoiceWriter($company, $includeDiscount));
    }

    /**
     * SAGA Incasari (receipts on outgoing invoices): root <Incasari>, children <Linie>
     *
     * @param Payment[] $payments
     * @param array     $accountMap  Optional overrides: ['cash'=>'...','bank_transfer'=>'...','card'=>'...']
     */
    public function generateReceiptsXml(array $payments, array $accountMap = []): string
    {
        return $this->render('Incasari', $payments, $this->paymentLineWriter($accountMap, true));
    }

    /**
     * @param iterable<Payment> $payments
     */
    public function streamReceiptsXml(iterable $payments, array $accountMap = []): void
    {
        $this->stream('Incasari', $payments, $this->paymentLineWriter($accountMap, true));
    }

    /**
     * SAGA Plati (payments on incoming invoices): root <Plati>, children <Linie>
     *
     * @param Payment[] $payments
     * @param array     $accountMap  Optional overrides: ['cash'=>'...','bank_transfer'=>'...','card'=>'...']
     */
    public function generatePaymentsXml(array $payments, array $accountMap = []): string
    {
        return $this->render('Plati', $payments, $this->paymentLineWriter($accountMap, false));
    }

    /**
     * @param iterable<Payment> $payments
     */
    public function streamPaymentsXml(iterable $payments, array $accountMap = []): void
    {
        $this->stream('Plati', $payments, $this->paymentLineWriter($accountMap, false));
    }

    /**
     * Builds the whole document in memory. Kept for the accounting ZIP export, which needs
     * each file as a string.
     *
     * @param callable(\XMLWriter, mixed): void $writeItem
     */
    private function render(string $root, iterable $items, callable $writeItem): string
    {
        $w = new \XMLWriter();
        $w->openMemory();
        $this->writeDocument($w, $root, $items, $writeItem, false);

        return $w->outputMemory();
    }

    /**
     * Writes the document to php://output, flushing the writer every 200 items so memory
     * does not grow with the number of rows.
     *
     * @param callable(\XMLWriter, mixed): void $writeItem
     */
    private function stream(string $root, iterable $items, callable $writeItem): void
    {
        $w = new \XMLWriter();
        if (!$w->openUri('php://output')) {
            throw new \RuntimeException('Cannot open php://output for the XML export.');
        }
        $this->writeDocument($w, $root, $items, $writeItem, true);
        $w->flush();
    }

    /**
     * Two-space indentation and the UTF-8 declaration: the same bytes DOMDocument produced
     * with formatOutput, which the former implementation used.
     *
     * @param callable(\XMLWriter, mixed): void $writeItem
     */
    private function writeDocument(\XMLWriter $w, string $root, iterable $items, callable $writeItem, bool $flush): void
    {
        $w->setIndent(true);
        $w->setIndentString('  ');
        $w->startDocument('1.0', 'UTF-8');
        $w->startElement($root);

        $written = 0;
        foreach ($items as $item) {
            $writeItem($w, $item);
            if ($flush && ++$written % 200 === 0) {
                $w->flush();
                flush();
            }
        }

        $w->endElement();
        $w->endDocument();
    }

    private function clientLine(\XMLWriter $w, Client $client): void
    {
        $w->startElement('Linie');
        $this->writeText($w, 'Cod', $this->resolveClientCode($client));
        $this->writeText($w, 'Denumire', $client->getName() ?? '');
        $this->writeText($w, 'Cod_fiscal', $client->getVatCode() ?: ($client->getCui() ?? ''));
        $this->writeText($w, 'Reg_com', $client->getRegistrationNumber() ?? '');
        $this->writeText($w, 'Tara', $client->getCountry());
        $this->writeText($w, 'Judet', $client->getCountry() === 'RO' ? ($client->getCounty() ?? '') : '');
        $this->writeText($w, 'Localitate', $client->getCity() ?? '');
        $this->writeText($w, 'Adresa', $client->getAddress() ?? '');
        $this->writeText($w, 'Cont_banca', $client->getBankAccount() ?? '');
        $this->writeText($w, 'Banca', $client->getBankName() ?? '');
        $this->writeText($w, 'Tel', $client->getPhone() ?? '');
        $this->writeText($w, 'Email', $client->getEmail() ?? '');
        $this->writeText($w, 'Discount', '');
        $this->writeText($w, 'Informatii', $client->getNotes() ?? '');
        $this->writeText($w, 'Guid_cod', $client->getId()?->toRfc4122() ?? '');
        $w->endElement();
    }

    /**
     * <Cod> is the 1-based position of the supplier in the file.
     */
    private function supplierLineWriter(): \Closure
    {
        $position = 0;

        return function (\XMLWriter $w, Supplier $supplier) use (&$position): void {
            $position++;
            $w->startElement('Linie');
            $this->writeText($w, 'Cod', (string) $position);
            $this->writeText($w, 'Denumire', $supplier->getName() ?? '');
            $this->writeText($w, 'Cod_fiscal', $supplier->getVatCode() ?: ($supplier->getCif() ?? ''));
            $this->writeText($w, 'Tara', $supplier->getCountry());
            $this->writeText($w, 'Localitate', $supplier->getCity() ?? '');
            $this->writeText($w, 'Adresa', $supplier->getAddress() ?? '');
            $this->writeText($w, 'Cont_banca', $supplier->getBankAccount() ?? '');
            $this->writeText($w, 'Banca', $supplier->getBankName() ?? '');
            $this->writeText($w, 'Tel', $supplier->getPhone() ?? '');
            $this->writeText($w, 'Email', $supplier->getEmail() ?? '');
            $this->writeText($w, 'Informatii', $supplier->getNotes() ?? '');
            $this->writeText($w, 'Guid_cod', $supplier->getId()?->toRfc4122() ?? '');
            $w->endElement();
        };
    }

    private function productLine(\XMLWriter $w, Product $product): void
    {
        $w->startElement('Linie');
        $this->writeText($w, 'Cod', $product->getCode() ?? '');
        $this->writeText($w, 'Denumire', $product->getName() ?? '');
        $this->writeText($w, 'Cod_NC', $product->getNcCode() ?? '');
        $this->writeText($w, 'Cod_CPV', $product->getCpvCode() ?? '');
        $this->writeText($w, 'UM', $product->getUnitOfMeasure());
        $this->writeText($w, 'Tip', $product->isService() ? 'Serviciu' : 'Marfa');
        $this->writeText($w, 'TVA', $product->getVatRate());
        $this->writeText($w, 'Pret', $product->getDefaultPrice());
        // Pret_TVA = price with VAT included
        $vatMultiplier = bcadd('1', bcdiv($product->getVatRate(), '100', 6), 6);
        $pretTva = bcmul($product->getDefaultPrice(), $vatMultiplier, 2);
        $this->writeText($w, 'Pret_TVA', $pretTva);
        $this->writeText($w, 'Cod_bare', '');
        $this->writeText($w, 'Informatii', $product->getDescription() ?? '');
        $this->writeText($w, 'Guid_cod', $product->getId()?->toRfc4122() ?? '');
        $w->endElement();
    }

    /**
     * Receipts (outgoing invoices) take the partner CIF from the invoice receiver,
     * payments (incoming invoices) from the invoice sender.
     */
    private function paymentLineWriter(array $accountMap, bool $receipts): \Closure
    {
        return function (\XMLWriter $w, Payment $payment) use ($accountMap, $receipts): void {
            $invoice = $payment->getInvoice();
            $invoiceNumber = $invoice?->getNumber() ?? '';
            $paymentDate = $payment->getPaymentDate()?->format('d.m.Y') ?? '';
            $explicatie = $payment->getNotes()
                ?: trim(sprintf('contravaloarea facturii %s%s', $invoiceNumber, $paymentDate !== '' ? ' din data de ' . $paymentDate : ''));
            $partnerCif = $receipts ? $invoice?->getReceiverCif() : $invoice?->getSenderCif();

            $method = $payment->getPaymentMethod();
            $w->startElement('Linie');
            $this->writeText($w, 'TipDocument', $this->mapPaymentMethodToTipDocument($method));
            $this->writeText($w, 'Data', $paymentDate);
            $this->writeText($w, 'Numar', $payment->getReference() ?: '-');
            $this->writeText($w, 'Suma', $payment->getAmount());
            $this->writeText($w, 'Cont', $this->mapPaymentMethodToAccount($method, $accountMap));
            $this->writeText($w, 'Explicatie', $explicatie);
            $this->writeText($w, 'FacturaNumar', $invoiceNumber);
            $this->writeText($w, 'FacturaID', preg_replace('/[^0-9]/', '', $invoiceNumber));
            $this->writeText($w, 'CodFiscal', $this->codFiscalForReceipt($method, $partnerCif));
            $w->endElement();
        };
    }

    private function invoiceWriter(Company $company, bool $includeDiscount): \Closure
    {
        return function (\XMLWriter $w, Invoice $invoice) use ($company, $includeDiscount): void {
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
        };
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
     * Resolve the SAGA partner code for a client. Prefers the imported client
     * code, then the fiscal code (VAT/CUI), then the client's stable UUID.
     * Never falls back to the row index: that was unstable across exports and
     * could collide with another client's real code (duplicate <Cod> merges
     * partners on import). An empty string is treated as "no value" so a
     * literal "0" code is still honoured.
     */
    private function resolveClientCode(Client $client): string
    {
        $code = $client->getClientCode();
        if ($code !== null && $code !== '') {
            return $code;
        }

        $cif = $client->getVatCode() ?: ($client->getCui() ?? '');
        if ($cif !== '') {
            return $cif;
        }

        return $client->getId()?->toRfc4122() ?? '';
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

    /**
     * Leaf element for the formats that DOMDocument used to write (clients, suppliers,
     * products, receipts, payments). Same escaping as a DOM text node: & < > and a carriage
     * return as &#13;, quotes left raw. An empty value gives <Name></Name>.
     */
    private function writeText(\XMLWriter $w, string $name, string $value): void
    {
        $w->startElement($name);
        if ($value === '') {
            $w->text('');
        } else {
            $w->writeRaw(str_replace(['&', '<', '>', "\r"], ['&amp;', '&lt;', '&gt;', '&#13;'], $value));
        }
        $w->endElement();
    }

    private function isReverseCharge(Invoice $invoice): bool
    {
        $typeCode = $invoice->getInvoiceTypeCode();

        return $typeCode === '389' || $typeCode === 'reverse_charge';
    }

    /**
     * Document type: space = regular invoice, A = delivery note, T = reverse charge,
     * X = the special regime of art. 314-315 (OSS).
     */
    private function getSagaInvoiceType(Invoice $invoice): string
    {
        if ($this->isReverseCharge($invoice)) {
            return 'T';
        }

        if ($this->isOss($invoice)) {
            return 'X';
        }

        return ' ';
    }

    /**
     * OSS: the company is registered for it and sells to a buyer of another member state who has no
     * valid VAT number, at the VAT rate of that state. An invoice carrying no VAT is an exemption,
     * an export or a non-taxable operation — not OSS, and it keeps the regular document type.
     */
    private function isOss(Invoice $invoice): bool
    {
        $company = $invoice->getCompany();
        $buyer = $this->resolveBuyer($invoice);
        $country = $buyer['country'] ?? null;
        $client = $invoice->getClient();

        return $company
            && $company->isOss()
            && $country !== null
            && $country !== 'RO'
            && in_array($country, self::EU_COUNTRY_CODES, true)
            && $client?->isViesValid() !== true
            && bccomp($invoice->getVatTotal(), '0', 2) > 0;
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
