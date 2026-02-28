<?php

namespace App\MessageHandler\Anaf;

use App\Entity\DeliveryNote;
use App\Enum\DeliveryNoteStatus;
use App\Message\Anaf\CheckETransportStatusMessage;
use App\Message\Anaf\SubmitETransportMessage;
use App\Service\Anaf\AnafTokenResolver;
use App\Service\Anaf\ETransportClient;
use App\Service\Anaf\ETransportValidator;
use App\Service\Anaf\ETransportXmlGenerator;
use App\Service\Storage\OrganizationStorageResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final class SubmitETransportHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ETransportValidator $validator,
        private readonly ETransportXmlGenerator $xmlGenerator,
        private readonly ETransportClient $eTransportClient,
        private readonly AnafTokenResolver $tokenResolver,
        private readonly MessageBusInterface $messageBus,
        private readonly OrganizationStorageResolver $storageResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SubmitETransportMessage $message): void
    {
        $note = $this->entityManager->getRepository(DeliveryNote::class)->find(
            Uuid::fromString($message->deliveryNoteId)
        );

        if ($note === null) {
            $this->logger->warning('SubmitETransportHandler: DeliveryNote not found.', [
                'deliveryNoteId' => $message->deliveryNoteId,
            ]);
            return;
        }

        // Guard: only process ISSUED delivery notes
        if ($note->getStatus() !== DeliveryNoteStatus::ISSUED) {
            $this->logger->info('SubmitETransportHandler: DeliveryNote status changed, skipping.', [
                'deliveryNoteId' => $message->deliveryNoteId,
                'status' => $note->getStatus()->value,
            ]);
            return;
        }

        // Guard: skip if already successfully uploaded (avoid re-upload)
        $allowedStatuses = [null, 'pending', 'validation_failed', 'upload_failed'];
        if (!in_array($note->getEtransportStatus(), $allowedStatuses, true)) {
            $this->logger->info('SubmitETransportHandler: Already submitted, skipping.', [
                'deliveryNoteId' => $message->deliveryNoteId,
                'etransportStatus' => $note->getEtransportStatus(),
            ]);
            return;
        }

        // Phase 1: Validate entity data (Schematron-equivalent rules)
        $entityErrors = $this->validator->validateEntity($note);
        if (!empty($entityErrors)) {
            $this->logger->error('SubmitETransportHandler: Validation failed.', [
                'deliveryNoteId' => $message->deliveryNoteId,
                'errors' => $entityErrors,
            ]);

            $note->setEtransportStatus('validation_failed');
            $note->setEtransportErrorMessage(
                implode('; ', array_map(fn ($e) => "[{$e['rule']}] {$e['message']}", $entityErrors))
            );
            $this->entityManager->flush();
            return;
        }

        // Phase 2: Generate XML
        $xml = $this->xmlGenerator->generateNotification($note);

        // Phase 3: Validate XML against XSD
        $xsdErrors = $this->validator->validateXml($xml);
        if (!empty($xsdErrors)) {
            $this->logger->error('SubmitETransportHandler: XSD validation failed.', [
                'deliveryNoteId' => $message->deliveryNoteId,
                'errors' => $xsdErrors,
            ]);

            $note->setEtransportStatus('validation_failed');
            $note->setEtransportErrorMessage(
                implode('; ', array_map(fn ($e) => "[{$e['rule']}] {$e['message']}", $xsdErrors))
            );
            $this->entityManager->flush();
            return;
        }

        // Phase 4: Validate XML against Schematron (XSLT2)
        $schematronResult = $this->validator->validateSchematron($xml);
        if (!$schematronResult->isValid) {
            $this->logger->error('SubmitETransportHandler: Schematron validation failed.', [
                'deliveryNoteId' => $message->deliveryNoteId,
                'errors' => array_map(fn ($e) => $e->toArray(), $schematronResult->errors),
            ]);

            $note->setEtransportStatus('validation_failed');
            $note->setEtransportErrorMessage(
                implode('; ', array_map(
                    fn ($e) => $e->ruleId ? "[{$e->ruleId}] {$e->message}" : $e->message,
                    $schematronResult->errors,
                ))
            );
            $this->entityManager->flush();
            return;
        }

        // Store XML in Flysystem
        $company = $note->getCompany();
        if (!$note->getEtransportXmlPath()) {
            $cif = (string) $company?->getCif();
            $year = $note->getIssueDate()?->format('Y') ?? date('Y');
            $month = $note->getIssueDate()?->format('m') ?? date('m');
            $xmlPath = 'etransport/' . $cif . '/' . $year . '/' . $month . '/' . $note->getId() . '.xml';
            $storage = $this->storageResolver->resolveForCompany($company);
            $storage->write($xmlPath, $xml);
            $note->setEtransportXmlPath($xmlPath);
        }

        // Resolve ANAF token
        $token = $this->tokenResolver->resolve($company);
        if ($token === null) {
            $this->logger->error('SubmitETransportHandler: No ANAF token available.', [
                'deliveryNoteId' => $message->deliveryNoteId,
            ]);

            $note->setEtransportStatus('upload_failed');
            $note->setEtransportErrorMessage('Nu exista un token ANAF valid pentru aceasta companie.');
            $this->entityManager->flush();
            return;
        }

        // Upload to ANAF
        $cif = (string) $company?->getCif();
        // Strip 'RO' prefix for CIF if present
        $cifClean = preg_replace('/^RO/i', '', $cif);

        $uploadResponse = $this->eTransportClient->upload($xml, $cifClean, $token);

        if (!$uploadResponse->success) {
            $this->logger->error('SubmitETransportHandler: ANAF upload failed.', [
                'deliveryNoteId' => $message->deliveryNoteId,
                'error' => $uploadResponse->errorMessage,
            ]);

            $note->setEtransportStatus('upload_failed');
            $note->setEtransportErrorMessage($uploadResponse->errorMessage);
            $this->entityManager->flush();
            return;
        }

        // Success
        $note->setEtransportUploadId($uploadResponse->uploadId);
        $note->setEtransportStatus('uploaded');
        $this->entityManager->flush();

        $this->logger->info('SubmitETransportHandler: Submitted successfully.', [
            'deliveryNoteId' => $message->deliveryNoteId,
            'uploadId' => $uploadResponse->uploadId,
        ]);

        // Dispatch status check
        $this->messageBus->dispatch(
            new CheckETransportStatusMessage(
                deliveryNoteId: $message->deliveryNoteId,
                attempt: 0,
            )
        );
    }
}
