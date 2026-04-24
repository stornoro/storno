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
     * SAGA Facturi: root <Facturi>, children <Factura>
     *
     * @param Invoice[] $invoices
     */
    public function generateInvoicesXml(array $invoices, Company $company, bool $includeDiscount = false): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('Facturi');
        $dom->appendChild($root);

        foreach ($invoices as $invoice) {
            $facturaNode = $dom->createElement('Factura');

            // ── Antet ──
            $antetNode = $dom->createElement('Antet');

            // Furnizor (company)
            $this->addElement($dom, $antetNode, 'FurnizorNume', $company->getName() ?? '');
            $this->addElement($dom, $antetNode, 'FurnizorCIF', (string) $company->getCif());
            $this->addElement($dom, $antetNode, 'FurnizorNrRegCom', $company->getRegistrationNumber() ?? '');
            $this->addElement($dom, $antetNode, 'FurnizorCapital', '');
            $this->addElement($dom, $antetNode, 'FurnizorTara', $company->getCountry() ?? 'RO');
            $this->addElement($dom, $antetNode, 'FurnizorLocalitate', $company->getCity() ?? '');
            $this->addElement($dom, $antetNode, 'FurnizorJudet', $company->getState() ?? '');
            $this->addElement($dom, $antetNode, 'FurnizorAdresa', $company->getAddress() ?? '');
            $this->addElement($dom, $antetNode, 'FurnizorTelefon', $company->getPhone() ?? '');
            $this->addElement($dom, $antetNode, 'FurnizorMail', $company->getEmail() ?? '');
            $this->addElement($dom, $antetNode, 'FurnizorBanca', $company->getBankName() ?? '');
            $this->addElement($dom, $antetNode, 'FurnizorIBAN', $company->getBankAccount() ?? '');
            $this->addElement($dom, $antetNode, 'FurnizorInformatiiSuplimentare', '');

            // Client — read from buyer snapshot (frozen at issue time) to
            // avoid showing the wrong details if the linked client is later
            // edited or re-linked.
            $buyer = $this->resolveBuyer($invoice);
            $this->addElement($dom, $antetNode, 'ClientNume', $invoice->getReceiverName() ?? ($buyer['name'] ?? ''));
            $this->addElement($dom, $antetNode, 'ClientInformatiiSuplimentare', '');
            $this->addElement($dom, $antetNode, 'ClientCIF', $invoice->getReceiverCif() ?? ($buyer['cui'] ?? ''));
            $this->addElement($dom, $antetNode, 'ClientNrRegCom', $buyer['registrationNumber'] ?? '');
            $clientCountry = $buyer['country'] ?? 'RO';
            $this->addElement($dom, $antetNode, 'ClientJudet', $clientCountry === 'RO' ? ($buyer['county'] ?? '') : '');
            $this->addElement($dom, $antetNode, 'ClientTara', $clientCountry);
            $this->addElement($dom, $antetNode, 'ClientLocalitate', $buyer['city'] ?? '');
            $this->addElement($dom, $antetNode, 'ClientAdresa', $buyer['address'] ?? '');
            $this->addElement($dom, $antetNode, 'ClientBanca', $buyer['bankName'] ?? '');
            $this->addElement($dom, $antetNode, 'ClientIBAN', $buyer['bankAccount'] ?? '');
            $this->addElement($dom, $antetNode, 'ClientTelefon', $buyer['phone'] ?? '');
            $this->addElement($dom, $antetNode, 'ClientMail', $buyer['email'] ?? '');

            // Factura metadata
            $this->addElement($dom, $antetNode, 'FacturaNumar', $invoice->getNumber() ?? '');
            $this->addElement($dom, $antetNode, 'FacturaData', $invoice->getIssueDate()?->format('d.m.Y') ?? '');
            $this->addElement($dom, $antetNode, 'FacturaScadenta', $invoice->getDueDate()?->format('d.m.Y') ?? '');
            $this->addElement($dom, $antetNode, 'FacturaTaxareInversa', $this->isReverseCharge($invoice) ? 'Da' : 'Nu');
            $this->addElement($dom, $antetNode, 'FacturaTVAIncasare', $invoice->isTvaLaIncasare() ? 'Da' : 'Nu');
            $this->addElement($dom, $antetNode, 'FacturaTip', $this->getSagaInvoiceType($invoice));
            $this->addElement($dom, $antetNode, 'FacturaInformatiiSuplimentare', '');
            $this->addElement($dom, $antetNode, 'FacturaMoneda', $invoice->getCurrency());
            $this->addElement($dom, $antetNode, 'FacturaGreutate', '0.000');

            // Discount at invoice level (optional)
            if ($includeDiscount && bccomp($invoice->getDiscount(), '0', 2) > 0) {
                $this->addElement($dom, $antetNode, 'FacturaDiscount', $invoice->getDiscount());
            }

            $facturaNode->appendChild($antetNode);

            // ── Detalii > Continut ──
            $detaliiNode = $dom->createElement('Detalii');
            $continutNode = $dom->createElement('Continut');

            $lineIndex = 0;
            foreach ($invoice->getLines() as $line) {
                $lineIndex++;
                $linieNode = $dom->createElement('Linie');

                $this->addElement($dom, $linieNode, 'LinieNrCrt', (string) $lineIndex);
                $this->addElement($dom, $linieNode, 'Descriere', $line->getDescription() ?? '');
                $this->addElement($dom, $linieNode, 'CodArticolFurnizor', $line->getProductCode() ?? '');
                $this->addElement($dom, $linieNode, 'CodArticolClient', $line->getBuyerItemIdentification() ?? '');
                $this->addElement($dom, $linieNode, 'CodBare', $line->getStandardItemIdentification() ?? '');
                $this->addElement($dom, $linieNode, 'InformatiiSuplimentare', $line->getLineNote() ?? '');
                $this->addElement($dom, $linieNode, 'UM', $line->getUnitOfMeasure());
                $this->addElement($dom, $linieNode, 'Cantitate', $line->getQuantity());
                $this->addElement($dom, $linieNode, 'Pret', $line->getUnitPrice());
                $this->addElement($dom, $linieNode, 'Valoare', $line->getLineTotal());
                $this->addElement($dom, $linieNode, 'ProcTVA', $line->getVatRate());
                $this->addElement($dom, $linieNode, 'TVA', $line->getVatAmount());

                $continutNode->appendChild($linieNode);
            }

            $detaliiNode->appendChild($continutNode);
            $facturaNode->appendChild($detaliiNode);

            // FacturaID at <Factura> level, after </Detalii>
            $this->addElement($dom, $facturaNode, 'FacturaID', preg_replace('/[^0-9]/', '', $invoice->getNumber() ?? ''));

            $root->appendChild($facturaNode);
        }

        return $dom->saveXML();
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

            $this->addElement($dom, $node, 'TipDocument', $this->mapPaymentMethodToTipDocument($payment->getPaymentMethod()));
            $this->addElement($dom, $node, 'Data', $paymentDate);
            $this->addElement($dom, $node, 'Numar', $payment->getReference() ?: '-');
            $this->addElement($dom, $node, 'Suma', $payment->getAmount());
            $this->addElement($dom, $node, 'Cont', $this->mapPaymentMethodToAccount($payment->getPaymentMethod(), $accountMap));
            $this->addElement($dom, $node, 'Explicatie', $explicatie);
            $this->addElement($dom, $node, 'FacturaNumar', $invoiceNumber);
            $this->addElement($dom, $node, 'FacturaID', preg_replace('/[^0-9]/', '', $invoiceNumber));
            $this->addElement($dom, $node, 'CodFiscal', $invoice?->getReceiverCif() ?? '');

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

            $this->addElement($dom, $node, 'TipDocument', $this->mapPaymentMethodToTipDocument($payment->getPaymentMethod()));
            $this->addElement($dom, $node, 'Data', $paymentDate);
            $this->addElement($dom, $node, 'Numar', $payment->getReference() ?: '-');
            $this->addElement($dom, $node, 'Suma', $payment->getAmount());
            $this->addElement($dom, $node, 'Cont', $this->mapPaymentMethodToAccount($payment->getPaymentMethod(), $accountMap));
            $this->addElement($dom, $node, 'Explicatie', $explicatie);
            $this->addElement($dom, $node, 'FacturaNumar', $invoiceNumber);
            $this->addElement($dom, $node, 'FacturaID', preg_replace('/[^0-9]/', '', $invoiceNumber));
            $this->addElement($dom, $node, 'CodFiscal', $invoice?->getSenderCif() ?? '');

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
            'card' => '5125',
        ];

        $merged = array_merge($defaults, $accountMap);

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
}
