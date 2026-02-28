<?php

namespace App\MessageHandler;

use App\Message\GeneratePdfMessage;
use App\Repository\InvoiceRepository;
use App\Service\DocumentPdfService;
use App\Service\InvoiceXmlResolver;
use App\Service\PdfGeneratorService;
use App\Service\Storage\OrganizationStorageResolver;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class GeneratePdfHandler
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly PdfGeneratorService $pdfGenerator,
        private readonly DocumentPdfService $documentPdfService,
        private readonly InvoiceXmlResolver $xmlResolver,
        private readonly FilesystemOperator $defaultStorage,
        private readonly OrganizationStorageResolver $storageResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(GeneratePdfMessage $message): void
    {
        $invoice = $this->invoiceRepository->find($message->invoiceId);
        if (!$invoice) {
            $this->logger->warning('Invoice not found for PDF generation', ['id' => $message->invoiceId]);
            return;
        }

        // Skip if PDF already generated
        if ($invoice->getPdfPath()) {
            return;
        }

        try {
            // Outgoing invoices: use customizable Twig templates
            if ($this->documentPdfService->isOutgoingInvoice($invoice)) {
                $pdfContent = $this->documentPdfService->generateInvoicePdf($invoice);
            } else {
                // Incoming invoices: use XML -> Java PDF service
                $xml = $this->xmlResolver->resolve($invoice);
                if (!$xml) {
                    $this->logger->error('No XML content available for PDF generation', ['id' => $message->invoiceId]);
                    return;
                }
                $pdfContent = $this->pdfGenerator->generatePdf($xml);
            }

            $cif = (string) $invoice->getCompany()->getCif();
            $issueDate = $invoice->getIssueDate();
            $pdfPath = sprintf(
                '%s/%s/%s/%s.pdf',
                $cif,
                $issueDate->format('Y'),
                $issueDate->format('m'),
                $invoice->getAnafMessageId() ?? (string) $invoice->getId()
            );

            $storage = $this->storageResolver->resolveForCompany($invoice->getCompany());
            $storage->write($pdfPath, $pdfContent);
            $invoice->setPdfPath($pdfPath);
            $this->entityManager->flush();

            $this->logger->info('PDF generated', ['path' => $pdfPath]);
        } catch (\Throwable $e) {
            $this->logger->error('PDF generation failed', [
                'invoiceId' => $message->invoiceId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
