<?php

namespace App\Service\EInvoice\Italy;

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

#[AutoconfigureTag('app.einvoice_submission_handler', ['provider' => 'sdi'])]
final class SdiSubmissionHandler implements EInvoiceSubmissionHandlerInterface
{
    public function __construct(
        private readonly FatturaPaXmlGenerator $xmlGenerator,
        private readonly SdiValidator $validator,
        private readonly SdiClient $client,
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
            ? $this->configResolver->resolve($company, EInvoiceProvider::SDI)
            : [];

        $hasDirectCert = !empty($configData['certPath']) && !empty($configData['certPassword']);
        $hasIntermediary = !empty($configData['apiEndpoint']) && !empty($configData['apiKey']);

        if ($hasDirectCert || $hasIntermediary) {
            $filename = 'IT' . $invoice->getCompany()?->getCif() . '_' . $invoice->getId() . '.xml';
            $response = $this->client->submit($xml, $filename, $configData);

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
            $submission->setErrorMessage('SDI: ' . $response->errorMessage);
            $submission->setMetadata(['xmlPath' => $xmlPath]);
            $this->entityManager->flush();
            return;
        }

        // No SDI credentials — XML generation only
        $submission->setStatus(EInvoiceSubmissionStatus::SUBMITTED);
        $submission->setMetadata([
            'xmlPath' => $xmlPath,
            'xmlGeneratedAt' => (new \DateTimeImmutable())->format('c'),
            'note' => 'FatturaPA XML generated. No SDI credentials configured — submit via intermediary.',
        ]);
        $this->entityManager->flush();
    }

    private function storeXml(Invoice $invoice, string $xml): string
    {
        $company = $invoice->getCompany();
        $cif = (string) $company?->getCif();
        $year = $invoice->getIssueDate()?->format('Y') ?? date('Y');
        $month = $invoice->getIssueDate()?->format('m') ?? date('m');
        $xmlPath = $cif . '/einvoice/sdi/' . $year . '/' . $month . '/' . $invoice->getId() . '.xml';

        $storage = $this->storageResolver->resolveForCompany($company);
        $storage->write($xmlPath, $xml);

        $this->logger->info('SdiSubmissionHandler: XML stored.', [
            'invoiceId' => (string) $invoice->getId(),
            'xmlPath' => $xmlPath,
        ]);

        return $xmlPath;
    }
}
