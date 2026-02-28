<?php

namespace App\Service\EInvoice\Germany;

use App\Entity\EInvoiceSubmission;
use App\Enum\EInvoiceSubmissionStatus;
use App\Message\EInvoice\CheckEInvoiceStatusMessage;
use App\Repository\CompanyEInvoiceConfigRepository;
use App\Service\EInvoice\EInvoiceStatusCheckerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Messenger\MessageBusInterface;

#[AutoconfigureTag('app.einvoice_status_checker', ['provider' => 'xrechnung'])]
final class XRechnungStatusChecker implements EInvoiceStatusCheckerInterface
{
    public function __construct(
        private readonly XRechnungClient $client,
        private readonly CompanyEInvoiceConfigRepository $configRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {}

    public function check(EInvoiceSubmission $submission, CheckEInvoiceStatusMessage $message): void
    {
        $company = $submission->getInvoice()?->getCompany();
        $config = $company !== null
            ? $this->configRepository->findByCompanyAndProvider($company, $submission->getProvider())
            : null;
        $credentials = $config?->getConfig() ?? [];

        try {
            $statusResponse = $this->client->checkStatus($submission->getExternalId(), $credentials);

            $submission->setStatus($statusResponse->status);
            if ($statusResponse->errorMessage) {
                $submission->setErrorMessage($statusResponse->errorMessage);
            }
            $submission->setMetadata(array_merge(
                $submission->getMetadata() ?? [],
                $statusResponse->metadata,
                ['lastCheckedAt' => (new \DateTimeImmutable())->format('c')]
            ));
            $this->entityManager->flush();

            if ($statusResponse->status === EInvoiceSubmissionStatus::PENDING) {
                $this->messageBus->dispatch(
                    new CheckEInvoiceStatusMessage(
                        submissionId: $message->submissionId,
                        attempt: $message->attempt + 1,
                    )
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('XRechnungStatusChecker: Status check failed.', [
                'submissionId' => $message->submissionId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
