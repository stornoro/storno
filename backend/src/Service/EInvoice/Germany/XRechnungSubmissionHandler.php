<?php

namespace App\Service\EInvoice\Germany;

use App\Entity\EInvoiceSubmission;
use App\Entity\Invoice;
use App\Enum\EInvoiceProvider;
use App\Enum\EInvoiceSubmissionStatus;
use App\Message\EInvoice\CheckEInvoiceStatusMessage;
use App\Service\EInvoice\EInvoiceConfigResolver;
use App\Service\EInvoice\EInvoiceSubmissionHandlerInterface;
use App\Service\Storage\OrganizationStorageResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Messenger\MessageBusInterface;

#[AutoconfigureTag('app.einvoice_submission_handler', ['provider' => 'xrechnung'])]
final class XRechnungSubmissionHandler implements EInvoiceSubmissionHandlerInterface
{
    public function __construct(
        private readonly XRechnungXmlGenerator $xmlGenerator,
        private readonly XRechnungValidator $validator,
        private readonly XRechnungClient $client,
        private readonly OrganizationStorageResolver $storageResolver,
        private readonly EInvoiceConfigResolver $configResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(Invoice $invoice, EInvoiceSubmission $submission): void
    {
        $validationResult = $this->validator->validate($invoice);
        if (!$validationResult->isValid) {
            $submission->setStatus(EInvoiceSubmissionStatus::REJECTED);
            $submission->setErrorMessage(
                implode('; ', array_map(fn ($e) => $e->message, $validationResult->errors))
            );
            $this->entityManager->flush();
            return;
        }

        $xml = $this->xmlGenerator->generate($invoice);
        $xmlPath = $this->storeXml($invoice, $xml);
        $submission->setXmlPath($xmlPath);

        $company = $invoice->getCompany();
        $configData = $company !== null
            ? $this->configResolver->resolve($company, EInvoiceProvider::XRECHNUNG)
            : [];

        if (!empty($configData['clientId']) && !empty($configData['clientSecret'])) {
            $response = $this->client->submit($xml, $configData);

            if ($response->success) {
                $submission->setExternalId($response->externalId);
                $submission->setStatus(EInvoiceSubmissionStatus::SUBMITTED);
                $submission->setMetadata(array_merge($response->metadata, [
                    'xmlPath' => $xmlPath,
                    'submittedAt' => (new \DateTimeImmutable())->format('c'),
                ]));
                $this->entityManager->flush();

                $this->messageBus->dispatch(
                    new CheckEInvoiceStatusMessage((string) $submission->getId())
                );
                return;
            }

            $submission->setStatus(EInvoiceSubmissionStatus::ERROR);
            $submission->setErrorMessage('ZRE API: ' . $response->errorMessage);
            $submission->setMetadata(['xmlPath' => $xmlPath]);
            $this->entityManager->flush();
            return;
        }

        // No API credentials — XML generation only
        $submission->setStatus(EInvoiceSubmissionStatus::SUBMITTED);
        $submission->setMetadata([
            'xmlPath' => $xmlPath,
            'xmlGeneratedAt' => (new \DateTimeImmutable())->format('c'),
            'note' => 'XML generated. No ZRE API credentials configured — upload manually.',
        ]);
        $this->entityManager->flush();
    }

    private function storeXml(Invoice $invoice, string $xml): string
    {
        $company = $invoice->getCompany();
        $cif = (string) $company?->getCif();
        $year = $invoice->getIssueDate()?->format('Y') ?? date('Y');
        $month = $invoice->getIssueDate()?->format('m') ?? date('m');
        $xmlPath = $cif . '/einvoice/xrechnung/' . $year . '/' . $month . '/' . $invoice->getId() . '.xml';

        $storage = $this->storageResolver->resolveForCompany($company);
        $storage->write($xmlPath, $xml);

        $this->logger->info('XRechnungSubmissionHandler: XML stored.', [
            'invoiceId' => (string) $invoice->getId(),
            'xmlPath' => $xmlPath,
        ]);

        return $xmlPath;
    }
}
