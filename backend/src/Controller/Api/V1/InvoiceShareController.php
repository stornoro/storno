<?php

namespace App\Controller\Api\V1;

use App\Repository\InvoiceShareTokenRepository;
use App\Repository\StripeConnectAccountRepository;
use App\Service\DocumentPdfService;
use App\Service\InvoiceXmlResolver;
use App\Service\PdfGeneratorService;
use App\Service\StripeConnectService;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/share')]
class InvoiceShareController extends AbstractController
{
    public function __construct(
        private readonly InvoiceShareTokenRepository $shareTokenRepository,
        private readonly StripeConnectAccountRepository $connectAccountRepository,
        private readonly StripeConnectService $connectService,
        private readonly DocumentPdfService $documentPdfService,
        private readonly InvoiceXmlResolver $xmlResolver,
        private readonly PdfGeneratorService $pdfGenerator,
        private readonly FilesystemOperator $defaultStorage,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/{token}', methods: ['GET'])]
    public function view(string $token): JsonResponse
    {
        $shareToken = $this->shareTokenRepository->findValidByToken($token);

        if (!$shareToken) {
            return $this->json([
                'error' => 'Link invalid sau expirat.',
            ], Response::HTTP_NOT_FOUND);
        }

        $invoice = $shareToken->getInvoice();
        $company = $shareToken->getCompany();

        $shareToken->recordView();
        $this->entityManager->flush();

        // Check if payment is available (respect invoice plataOnline setting)
        $paymentEnabled = false;
        $amountDue = '0';
        $allowPartialPayments = false;
        $successMessage = null;
        if ($invoice->isPlataOnline() && $company) {
            $connectAccount = $this->connectAccountRepository->findByCompany($company);
            if ($connectAccount && $connectAccount->isChargesEnabled()) {
                $total = (float) $invoice->getTotal();
                $paid = (float) $invoice->getAmountPaid();
                $remaining = $total - $paid;
                if ($remaining > 0) {
                    $paymentEnabled = true;
                    $amountDue = number_format($remaining, 2, '.', '');
                }
                $allowPartialPayments = $connectAccount->isAllowPartialPayments();
                $successMessage = $connectAccount->getSuccessMessage();
            }
        }

        return $this->json([
            'invoiceNumber' => $invoice->getNumber(),
            'issueDate' => $invoice->getIssueDate()?->format('Y-m-d'),
            'dueDate' => $invoice->getDueDate()?->format('Y-m-d'),
            'total' => $invoice->getTotal(),
            'currency' => $invoice->getCurrency(),
            'companyName' => $company?->getName(),
            'senderName' => $invoice->getSenderName(),
            'senderCif' => $this->formatCifWithPrefix($invoice->getSenderCif(), $company?->isVatPayer() ?? false),
            'receiverName' => $invoice->getReceiverName(),
            'receiverCif' => $this->formatCifWithPrefix($invoice->getReceiverCif(), $invoice->getClient()?->isVatPayer() ?? false),
            'hasPdf' => $invoice->getPdfPath() !== null || $invoice->getXmlPath() !== null,
            'hasXml' => $invoice->getXmlPath() !== null,
            'expiresAt' => $shareToken->getExpiresAt()?->format('c'),
            'paymentEnabled' => $paymentEnabled,
            'amountDue' => $amountDue,
            'amountPaid' => $invoice->getAmountPaid(),
            'paidAt' => $invoice->getPaidAt()?->format('c'),
            'allowPartialPayments' => $allowPartialPayments,
            'successMessage' => $successMessage,
        ]);
    }

    #[Route('/{token}/pay', methods: ['POST'])]
    public function pay(string $token, Request $request): JsonResponse
    {
        $shareToken = $this->shareTokenRepository->findValidByToken($token);

        if (!$shareToken) {
            return $this->json([
                'error' => 'Link invalid sau expirat.',
            ], Response::HTTP_NOT_FOUND);
        }

        $invoice = $shareToken->getInvoice();

        $data = json_decode($request->getContent(), true) ?? [];
        $requestedAmount = isset($data['amount']) ? (string) $data['amount'] : null;

        try {
            $url = $this->connectService->createPaymentSession($invoice, $shareToken, $requestedAmount);

            return $this->json(['url' => $url]);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('Payment session creation failed', ['error' => $e->getMessage()]);

            return $this->json(['error' => 'Failed to create payment session'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/{token}/pdf', methods: ['GET'])]
    public function pdf(string $token): Response
    {
        $shareToken = $this->shareTokenRepository->findValidByToken($token);

        if (!$shareToken) {
            return $this->json([
                'error' => 'Link invalid sau expirat.',
            ], Response::HTTP_NOT_FOUND);
        }

        $invoice = $shareToken->getInvoice();

        // Outgoing invoices: generate using company's selected design template
        if ($this->documentPdfService->isOutgoingInvoice($invoice)) {
            try {
                $pdfContent = $this->documentPdfService->generateInvoicePdf($invoice);

                return new Response($pdfContent, 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => sprintf('inline; filename="factura-%s.pdf"', $invoice->getNumber()),
                ]);
            } catch (\Throwable) {
                return $this->json(['error' => 'Generarea PDF a esuat.'], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        // Incoming invoices: serve cached PDF or generate from XML via Java service
        $pdfPath = $invoice->getPdfPath();
        if ($pdfPath && $this->defaultStorage->fileExists($pdfPath)) {
            $pdfContent = $this->defaultStorage->read($pdfPath);

            return new Response($pdfContent, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => sprintf('inline; filename="factura-%s.pdf"', $invoice->getNumber()),
            ]);
        }

        $xml = $this->xmlResolver->resolve($invoice);
        if (!$xml) {
            return $this->json(['error' => 'PDF indisponibil.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $pdfContent = $this->pdfGenerator->generatePdf($xml);
        } catch (\Throwable) {
            return $this->json(['error' => 'Generarea PDF a esuat.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new Response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="factura-%s.pdf"', $invoice->getNumber()),
        ]);
    }

    #[Route('/{token}/xml', methods: ['GET'])]
    public function xml(string $token): Response
    {
        $shareToken = $this->shareTokenRepository->findValidByToken($token);

        if (!$shareToken) {
            return $this->json([
                'error' => 'Link invalid sau expirat.',
            ], Response::HTTP_NOT_FOUND);
        }

        $invoice = $shareToken->getInvoice();

        $xml = $this->xmlResolver->resolve($invoice);
        if (!$xml) {
            return $this->json(['error' => 'XML indisponibil.'], Response::HTTP_NOT_FOUND);
        }

        return new Response($xml, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => sprintf('attachment; filename="factura-%s.xml"', $invoice->getNumber()),
        ]);
    }

    private function formatCifWithPrefix(?string $cif, bool $isVatPayer): ?string
    {
        if (!$cif) {
            return null;
        }
        if ($isVatPayer && preg_match('/^\d+$/', $cif)) {
            return 'RO' . $cif;
        }
        return $cif;
    }
}
