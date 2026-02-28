<?php

namespace App\Controller\Frontend\Account;

use App\Entity\Invoice;
use App\Invoice\Invoice\Invoice as UBLInvoice;
use App\Manager\InvoiceManager;
use App\Utils\Functions;
use JMS\Serializer\SerializerInterface;
use Knp\Bundle\SnappyBundle\Snappy\Response\PdfResponse;
use Knp\Snappy\Pdf;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/api/account')]
#[IsGranted('ROLE_USER', statusCode: 403)]
class InvoiceController extends AbstractController
{

    #[Route(
        '/me/invoices',
        name: 'frontend_api_user_invoices',
        methods: ['GET'],
        defaults: [
            '_api_resource_class' => Invoice::class,
            '_api_operation_name' => 'user-invoices',
        ]
    )]
    public function invoices(Request $request, InvoiceManager $invoiceManager)
    {
        $user = $this->getUser();
        $page = (int) $request->query->get('page', 1);
        $query = $request->query->get('query');
        $order = $request->query->all('order');
        $direction = $request->query->get('direction');
        $status = $request->query->get('status');
        $perPage = (int)$request->query->get('perPage', 10);

        $filterParams = [
            'query' => $query,
            'page' => $page,
            'order' => $order,
            'direction' => $direction,
            'status' => $status,
            'perPage' => $perPage
        ];
        $invoices = $invoiceManager
            ->listInvoices($filterParams);

        return $invoices;
    }
    #[Route(
        '/me/invoice/download/{invoiceName}.{type}',
        name: 'frontend_api_user_invoice_download',
        methods: ['GET']
    )]
    public function download(string $invoiceName, string $type, Request $request, InvoiceManager $invoiceManager, Pdf $pdf, SerializerInterface $serializer)
    {

        $data = $invoiceManager->download($invoiceName, $type);
        if ($type == 'pdf') {
            $xml = Functions::unzip(base64_decode($data['content']));
            /** @var UBLInvoice $invoice */
            $invoice = $serializer->deserialize($xml, UBLInvoice::class, 'xml');

            $yourXML = $invoice->getAccountingSupplierParty();
            $clientXML = $invoice->getAccountingCustomerParty();
            $your = [
                'name' => $yourXML->getParty()->getLegalEntity()->getRegistrationNumber(),
                'address' => $yourXML->getParty()->getPostalAddress()->getStreetName(),
                'city' => $yourXML->getParty()->getPostalAddress()->getCityName(),
                'state' => $yourXML->getParty()->getPostalAddress()->getCountrySubentity(),
                // 'btw' => '123456789',
                // 'bank' => 'Some Bank',
                // 'iban' => 'NL91 ABNA 0417 1643 00',
                // 'bic' => 'ABNANL2A',
                // 'telephone' => '123-456-7890',
                // 'email' => 'info@example.com',
                // 'website' => 'http://www.example.com',
            ];

            $client = [
                'name' => $clientXML->getParty()->getLegalEntity()->getRegistrationNumber(),
                'address' => $clientXML->getParty()->getPostalAddress()->getStreetName(),
                'city' => $clientXML->getParty()->getPostalAddress()->getCityName(),

            ];


            $date = new \DateTime();
            // $orderNr = 'ORD' . rand(1000, 9999); // Example order number
            $number = $invoice->getId();
            $lines = [];

            foreach ($invoice->getInvoiceLines() as $line) {
                $lines[] = [
                    'desc' => sprintf('%s - %s', $line->getItem()->getItem(), $line->getItem()->getDescription()),
                    'type' => $line->getInvoicedQuantity()->getUnitCode(),
                    'amount' => $line->getInvoicedQuantity()->getValue(),
                    'price' => $line->getPrice()->getPriceAmount()->getValue(),
                ];
            }

            $money = [
                'totalWithoutTax' => array_sum(array_column($lines, 'price')),
                'totalTax' => 0.21 * array_sum(array_column($lines, 'price')),
                'total' => 1.21 * array_sum(array_column($lines, 'price')),
            ];

            $invoiceDetails = [
                'issueDate' => $invoice->getIssueDate(),
                'dueDate' => $invoice->getDueDate()
            ];

            $html = $this->renderView('invoice/generate_invoice.html.twig', [
                'your' => $your,
                'client' => $client,
                'date' => $date,
                // 'orderNr' => $orderNr,
                'number' => $number,
                'lines' => $lines,
                'money' => $money,
                'invoice' => $invoiceDetails
            ]);

            return new PdfResponse(
                $pdf->getOutputFromHtml($html),
                sprintf('%s.%s', $number, $type)
            );
        }
        // $response = new Response($data['content']);

        // Create the disposition of the file
        // $disposition = $response->headers->makeDisposition(
        //     ResponseHeaderBag::DISPOSITION_ATTACHMENT,
        //     $data['filename']
        // );
        // Set the content disposition
        // $response->headers->set('Content-Disposition', $disposition);

        // Dispatch request
        return $this->json($data);
    }
}
