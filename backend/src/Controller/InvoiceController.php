<?php

namespace App\Controller;

use App\Invoice\AccountParty;
use App\Invoice\Amount;
use App\Invoice\Invoice\GenerateInvoice;
use App\Invoice\Invoice\Invoice;
use App\Invoice\Invoice\Quantity;
use App\Invoice\Party\PartyIdentification;
use App\Invoice\Party\PartyName;
use JMS\Serializer\SerializerInterface;
use Knp\Bundle\SnappyBundle\Snappy\Response\PdfResponse;
use Knp\Snappy\Pdf;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Encoder\XmlEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

class InvoiceController extends AbstractController
{
    #[Route('/invoice/pdf', name: 'app_invoice_html')]
    public function generateInvoice(Request $request, Pdf $pdf): Response
    {
        $your = [
            'name' => 'Your Company',
            'address' => '123 Main Street',
            'city' => 'Anytown',
            'btw' => '123456789',
            'bank' => 'Some Bank',
            'iban' => 'NL91 ABNA 0417 1643 00',
            'bic' => 'ABNANL2A',
            'telephone' => '123-456-7890',
            'email' => 'info@example.com',
            'website' => 'http://www.example.com',
        ];

        $client = [
            'name' => 'Client Company',
            'address' => '456 Business Avenue',
            'city' => 'Client City',
            'telephone' => '987-654-3210',
            'btw' => '987654321',
        ];

        $date = new \DateTime();
        $orderNr = 'ORD' . rand(1000, 9999); // Example order number
        $number = 'INV' . rand(100, 999); // Example invoice number

        $lines = [];
        for ($i = 1; $i <= 7; $i++) {
            $lines[] = [
                'desc' => "Product $i",
                'type' => "Unit",
                'amount' => rand(1, 5),
                'price' => rand(50, 200),
            ];
        }

        $money = [
            'totalWithoutTax' => array_sum(array_column($lines, 'price')),
            'totalTax' => 0.21 * array_sum(array_column($lines, 'price')),
            'total' => 1.21 * array_sum(array_column($lines, 'price')),
        ];

        $invoiceDetails = [
            'issueDate' => $date,
            'dueDate' => $date
        ];

        $html = $this->renderView('invoice/generate_invoice.html.twig', [
            'your' => $your,
            'client' => $client,
            'date' => $date,
            'orderNr' => $orderNr,
            'number' => $number,
            'lines' => $lines,
            'money' => $money,
            'invoice' => $invoiceDetails
        ]);


        return new PdfResponse(
            $pdf->getOutputFromHtml($html),
            sprintf('%s.pdf', $number)
        );
    }

    #[Route('/invoice/xml', name: 'app_invoice_xml')]
    public function generateInvoiceXML(Request $request, SerializerInterface $serializer): Response
    {
        $taxScheme = (new \App\Invoice\Party\TaxScheme())
            ->setId('VAT');
        // FURNIZOR
        $furnizorCountry = (new \App\Invoice\Account\Country())
            ->setIdentificationCode('RO');
        $furnizorAddress = (new \App\Invoice\Account\PostalAddress())
            ->setStreetName('address')
            ->setCityName('City')
            ->setCountrySubentity('RO-B')
            ->setCountry($furnizorCountry)
            //
        ;
        $paymentMeans = (new  \App\Invoice\Payment\PaymentMeans())
            ->setPaymentMeansCode(68, [])
            ->setPaymentId('Private notes');
        $furnizorLegalEntity = (new \App\Invoice\Legal\LegalEntity())
            ->setRegistrationNumber('123123')
            ->setCompanyId('123123');


        $furnizorPartyTaxScheme = (new \App\Invoice\Party\PartyTaxScheme())
            ->setTaxScheme($taxScheme)
            ->setCompanyId('123123');

        $furnizorCompany = new AccountParty(
            (new \App\Invoice\Party\Party())
                ->setPartyIdentificationId(new PartyIdentification('123123'))
                ->setName(new PartyName('TEST COMPAN NAME SRL'))
                ->setLegalEntity($furnizorLegalEntity)
                ->setPartyTaxScheme($furnizorPartyTaxScheme)
                ->setPostalAddress($furnizorAddress)
        );

        // Client
        $clientLegalEntity = (new \App\Invoice\Legal\LegalEntity())
            ->setRegistrationNumber('Client nume')
            ->setCompanyId('333333');
        $clientCountry = (new \App\Invoice\Account\Country())
            ->setIdentificationCode('RO');
        $clientPartyTaxScheme = (new \App\Invoice\Party\PartyTaxScheme())
            ->setTaxScheme($taxScheme)
            ->setCompanyId('333333');

        $clientAddress = (new \App\Invoice\Account\PostalAddress())
            ->setStreetName('Client adresa')
            ->setCityName('B')
            ->setCountrySubentity('RO-B')
            ->setCountry($clientCountry)
            //
        ;

        $clientContact = (new \App\Invoice\Account\Contact())
            ->setName('Pavel Andrei');

        $clientCompany = new AccountParty(
            (new \App\Invoice\Party\Party())
                ->setPartyIdentificationId(new PartyIdentification('123333'))
                ->setName(new PartyName('Pavel Andrei'))
                ->setLegalEntity($clientLegalEntity)
                ->setPartyTaxScheme($clientPartyTaxScheme)
                ->setPostalAddress($clientAddress)
                ->setContact($clientContact)
        );


        $legalMonetaryTotal = (new \App\Invoice\Legal\LegalMonetaryTotal())
            ->setLineExtensionAmount(new Amount(null, 10))
            ->setTaxExclusiveAmount(new Amount(null, 10))
            ->setTaxInclusiveAmount(new Amount(null, 10))
            ->setPayableAmount(new Amount(null, 10))
            //
        ;

        $classifiedTaxCategory = (new \App\Invoice\Tax\ClassifiedTaxCategory())
            ->setId('S')
            ->setPercent(19)
            ->setTaxScheme($taxScheme);

        // Product
        $productItem = (new \App\Invoice\Item())
            ->setName('name')
            ->setClassifiedTaxCategory($classifiedTaxCategory)
            ->setDescription('notes');

        // Price
        $price = (new \App\Invoice\Payment\Price())
            ->setBaseQuantity(1)
            ->setPriceAmount(new Amount(null, 10));

        // InvoicePeriod
        $invoicePeriod = (new \App\Invoice\Invoice\InvoicePeriod())
            ->setStartDate((new \DateTime()));

        // Invoice Line(s)
        $invoiceLine = (new \App\Invoice\Invoice\InvoiceLine())
            ->setId(1)
            ->setItem($productItem)
            ->setPrice($price)
            ->setInvoicePeriod($invoicePeriod)
            ->setLineExtensionAmount(new Amount(null, 10))
            ->setInvoicedQuantity(new Quantity(null, 1));

        $invoiceLines = [$invoiceLine];

        $taxCategory = (new \App\Invoice\Tax\TaxCategory())
            ->setId('S', [])
            ->setPercent(19)
            ->setTaxScheme($taxScheme);

        $taxSubTotal = (new \App\Invoice\Tax\TaxSubTotal())
            ->setTaxableAmount(new Amount(null, 10))
            ->setTaxAmount(new Amount(null, 10))
            ->setTaxCategory($taxCategory);

        $taxTotal = (new \App\Invoice\Tax\TaxTotal())
            ->setTaxSubtotal($taxSubTotal)
            ->setTaxAmount(new Amount(null, 10));

        // Payment Terms
        $paymentTerms = (new \App\Invoice\Payment\PaymentTerms())
            ->setNote('terms and conditions');
        // Delivery
        $delivery = (new \App\Invoice\Account\Delivery())
            ->setActualDeliveryDate((new \DateTime()))
            ->setDeliveryLocation($clientAddress);
        $orderReference = (new \App\Invoice\Payment\OrderReference())
            ->setId('#332o2oos0'); // Order reference


        // Invoice object
        $XMLinvoice = (new  \App\Invoice\Invoice\Invoice())
            ->setUBLVersionID(null)
            ->setCustomizationID('urn:cen.eu:en16931:2017#compliant#urn:efactura.mfinante.ro:CIUS-RO:1.0.1')
            ->setId('TEST12345')
            ->setDocumentCurrencyCode('RON')
            ->setIssueDate((new \DateTime()))
            ->setNote('NOTES TO INVOICE')
            ->setDelivery($delivery)
            ->setAccountingSupplierParty($furnizorCompany)
            ->setAccountingCustomerParty($clientCompany)
            ->setInvoiceLines($invoiceLines)
            ->setLegalMonetaryTotal($legalMonetaryTotal)
            ->setPaymentTerms($paymentTerms)
            ->setInvoicePeriod($invoicePeriod)
            ->setPaymentMeans([$paymentMeans])
            ->setBuyerReference('Private notes')
            ->setOrderReference($orderReference)
            ->setTaxTotal($taxTotal);

        $invoice = $serializer->serialize($XMLinvoice, 'xml');
        print_r($invoice);
        exit;
        $invoice = $serializer->deserialize($outputXMLString, Invoice::class, 'xml');
        print_r($invoice);
        exit;
    }
}
