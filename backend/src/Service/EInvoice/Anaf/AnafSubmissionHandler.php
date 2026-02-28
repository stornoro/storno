<?php

namespace App\Service\EInvoice\Anaf;

use App\Entity\DocumentEvent;
use App\Entity\EInvoiceSubmission;
use App\Entity\Invoice;
use App\Enum\DocumentStatus;
use App\Enum\EInvoiceSubmissionStatus;
use App\Message\EInvoice\CheckEInvoiceStatusMessage;
use App\Service\Anaf\AnafTokenResolver;
use App\Service\Anaf\EFacturaClient;
use App\Service\Anaf\EFacturaValidator;
use App\Service\Anaf\UblXmlGenerator;
use App\Service\EInvoice\EInvoiceSubmissionHandlerInterface;
use App\Service\Storage\OrganizationStorageResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Messenger\MessageBusInterface;

#[AutoconfigureTag('app.einvoice_submission_handler', ['provider' => 'anaf'])]
final class AnafSubmissionHandler implements EInvoiceSubmissionHandlerInterface
{
    public function __construct(
        private readonly EFacturaValidator $validator,
        private readonly UblXmlGenerator $xmlGenerator,
        private readonly EFacturaClient $client,
        private readonly AnafTokenResolver $tokenResolver,
        private readonly OrganizationStorageResolver $storageResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(Invoice $invoice, EInvoiceSubmission $submission): void
    {
        // Guard: only process if invoice status is eligible
        $allowedStatuses = [DocumentStatus::ISSUED, DocumentStatus::SENT_TO_PROVIDER, DocumentStatus::REFUND];
        if (!in_array($invoice->getStatus(), $allowedStatuses, true)) {
            $this->logger->info('AnafSubmissionHandler: Invoice status not eligible, skipping.', [
                'invoiceId' => (string) $invoice->getId(),
                'status' => $invoice->getStatus()->value,
            ]);
            $submission->setStatus(EInvoiceSubmissionStatus::ERROR);
            $submission->setErrorMessage('Invoice status not eligible for ANAF submission: ' . $invoice->getStatus()->value);
            $this->entityManager->flush();
            return;
        }

        // Validate
        $validationResult = $this->validator->validate($invoice);
        if (!$validationResult->isValid) {
            $errorMessage = implode('; ', array_map(fn ($e) => $e->message, $validationResult->errors));

            $submission->setStatus(EInvoiceSubmissionStatus::REJECTED);
            $submission->setErrorMessage($errorMessage);

            $invoice->setStatus(DocumentStatus::REJECTED);
            $invoice->setAnafStatus('validation_failed');
            $invoice->setAnafErrorMessage($errorMessage);
            $this->entityManager->flush();
            return;
        }

        $company = $invoice->getCompany();

        // Generate UBL XML
        $xml = $this->xmlGenerator->generate($invoice);

        // Store XML if not already stored
        if (!$invoice->getXmlPath()) {
            $cif = (string) $company?->getCif();
            $year = $invoice->getIssueDate()?->format('Y') ?? date('Y');
            $month = $invoice->getIssueDate()?->format('m') ?? date('m');
            $xmlPath = $cif . '/' . $year . '/' . $month . '/' . $invoice->getId() . '.xml';
            $storage = $this->storageResolver->resolveForCompany($company);
            $storage->write($xmlPath, $xml);
            $invoice->setXmlPath($xmlPath);
        }

        // Resolve ANAF OAuth token
        $token = $this->tokenResolver->resolve($company);
        if ($token === null) {
            $submission->setStatus(EInvoiceSubmissionStatus::ERROR);
            $submission->setErrorMessage('Nu exista un token ANAF valid pentru aceasta companie.');

            $invoice->setStatus(DocumentStatus::REJECTED);
            $invoice->setAnafStatus('no_token');
            $invoice->setAnafErrorMessage('Nu exista un token ANAF valid pentru aceasta companie.');
            $this->entityManager->flush();
            return;
        }

        // Upload to ANAF e-Factura
        $cif = (string) $company?->getCif();
        $uploadResponse = $this->client->upload($xml, $cif, $token);

        if (!$uploadResponse->success) {
            $submission->setStatus(EInvoiceSubmissionStatus::ERROR);
            $submission->setErrorMessage($uploadResponse->errorMessage);

            $invoice->setStatus(DocumentStatus::REJECTED);
            $invoice->setAnafStatus('upload_failed');
            $invoice->setAnafErrorMessage($uploadResponse->errorMessage);
            $this->entityManager->flush();
            return;
        }

        // Success — sync back to Invoice and create DocumentEvent
        $submission->setExternalId($uploadResponse->uploadId);
        $submission->setStatus(EInvoiceSubmissionStatus::SUBMITTED);
        $submission->setXmlPath($invoice->getXmlPath());
        $submission->setMetadata([
            'uploadId' => $uploadResponse->uploadId,
            'submittedAt' => (new \DateTimeImmutable())->format('c'),
        ]);

        $previousStatus = $invoice->getStatus();
        $invoice->setAnafUploadId($uploadResponse->uploadId);
        $invoice->setAnafStatus('uploaded');
        $invoice->setSyncedAt(new \DateTimeImmutable());
        $invoice->setStatus(DocumentStatus::SENT_TO_PROVIDER);

        $event = new DocumentEvent();
        $event->setPreviousStatus($previousStatus);
        $event->setNewStatus(DocumentStatus::SENT_TO_PROVIDER);
        $event->setMetadata(['action' => 'submitted_to_anaf', 'uploadId' => $uploadResponse->uploadId]);
        $invoice->addEvent($event);

        $this->entityManager->flush();

        $this->logger->info('AnafSubmissionHandler: Invoice submitted successfully.', [
            'invoiceId' => (string) $invoice->getId(),
            'uploadId' => $uploadResponse->uploadId,
        ]);

        $this->messageBus->dispatch(
            new CheckEInvoiceStatusMessage((string) $submission->getId())
        );
    }
}
