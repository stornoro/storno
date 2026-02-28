<?php

namespace App\MessageHandler\EInvoice;

use App\Entity\EInvoiceSubmission;
use App\Enum\EInvoiceSubmissionStatus;
use App\Message\EInvoice\CheckEInvoiceStatusMessage;
use App\Service\EInvoice\EInvoiceStatusCheckerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final class CheckEInvoiceStatusHandler
{
    private const MAX_ATTEMPTS = 10;

    /**
     * @param ServiceLocator<EInvoiceStatusCheckerInterface> $statusCheckers
     *        Tagged service locator keyed by EInvoiceProvider::value (e.g. 'anaf', 'xrechnung', …).
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly ServiceLocator $statusCheckers,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(CheckEInvoiceStatusMessage $message): void
    {
        $submission = $this->entityManager->getRepository(EInvoiceSubmission::class)->find(
            Uuid::fromString($message->submissionId)
        );

        if ($submission === null) {
            $this->logger->warning('CheckEInvoiceStatusHandler: Submission not found.', [
                'submissionId' => $message->submissionId,
            ]);
            return;
        }

        // Skip if already in a terminal state
        if ($submission->getStatus()->isTerminal()) {
            return;
        }

        // Skip if no external ID (XML-only submission — no API call was made)
        if ($submission->getExternalId() === null) {
            $submission->setStatus(EInvoiceSubmissionStatus::ACCEPTED);
            $submission->setMetadata(array_merge($submission->getMetadata() ?? [], [
                'note' => 'No API submission — XML generation only. Marked as accepted.',
            ]));
            $this->entityManager->flush();
            return;
        }

        if ($message->attempt >= self::MAX_ATTEMPTS) {
            $submission->setStatus(EInvoiceSubmissionStatus::ERROR);
            $submission->setErrorMessage('Max status check attempts exceeded.');
            $this->entityManager->flush();
            return;
        }

        try {
            /** @var EInvoiceStatusCheckerInterface $checker */
            $checker = $this->statusCheckers->get($submission->getProvider()->value);
            $checker->check($submission, $message);
        } catch (\Throwable $e) {
            $this->logger->error('CheckEInvoiceStatusHandler: Status check failed.', [
                'submissionId' => $message->submissionId,
                'provider' => $submission->getProvider()->value,
                'error' => $e->getMessage(),
            ]);

            // Re-dispatch for retry unless we have exhausted attempts
            if ($message->attempt < self::MAX_ATTEMPTS - 1) {
                $this->messageBus->dispatch(
                    new CheckEInvoiceStatusMessage(
                        submissionId: $message->submissionId,
                        attempt: $message->attempt + 1,
                    )
                );
            } else {
                $submission->setStatus(EInvoiceSubmissionStatus::ERROR);
                $submission->setErrorMessage($e->getMessage());
                $this->entityManager->flush();
            }
        }
    }
}
