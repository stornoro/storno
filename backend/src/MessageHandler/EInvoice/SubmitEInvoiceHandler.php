<?php

namespace App\MessageHandler\EInvoice;

use App\Entity\EInvoiceSubmission;
use App\Entity\Invoice;
use App\Enum\EInvoiceProvider;
use App\Enum\EInvoiceSubmissionStatus;
use App\Message\EInvoice\SubmitEInvoiceMessage;
use App\Service\EInvoice\EInvoiceSubmissionHandlerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final class SubmitEInvoiceHandler
{
    /**
     * @param ServiceLocator<EInvoiceSubmissionHandlerInterface> $submissionHandlers
     *        Tagged service locator keyed by EInvoiceProvider::value (e.g. 'anaf', 'xrechnung', …).
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ServiceLocator $submissionHandlers,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(SubmitEInvoiceMessage $message): void
    {
        $invoice = $this->entityManager->getRepository(Invoice::class)->find(
            Uuid::fromString($message->invoiceId)
        );

        if ($invoice === null) {
            $this->logger->warning('SubmitEInvoiceHandler: Invoice not found.', [
                'invoiceId' => $message->invoiceId,
            ]);
            return;
        }

        $provider = EInvoiceProvider::from($message->provider);

        // Guard: skip if there is already an active submission for this invoice
        $activeStatuses = [EInvoiceSubmissionStatus::PENDING, EInvoiceSubmissionStatus::SUBMITTED, EInvoiceSubmissionStatus::ACCEPTED];
        $existingSubmission = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(EInvoiceSubmission::class, 's')
            ->where('s.invoice = :invoice')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('invoice', $invoice)
            ->setParameter('statuses', $activeStatuses)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        if ($existingSubmission !== null) {
            $this->logger->info('SubmitEInvoiceHandler: Invoice already has an active submission, skipping.', [
                'invoiceId' => $message->invoiceId,
                'existingSubmissionId' => (string) $existingSubmission->getId(),
                'existingStatus' => $existingSubmission->getStatus()->value,
            ]);
            return;
        }

        // Create submission record
        $submission = new EInvoiceSubmission();
        $submission->setInvoice($invoice);
        $submission->setProvider($provider);
        $submission->setStatus(EInvoiceSubmissionStatus::PENDING);
        $this->entityManager->persist($submission);
        $this->entityManager->flush();

        try {
            /** @var EInvoiceSubmissionHandlerInterface $handler */
            $handler = $this->submissionHandlers->get($provider->value);
            $handler->handle($invoice, $submission);
        } catch (\Throwable $e) {
            $this->logger->error('SubmitEInvoiceHandler: Submission failed.', [
                'invoiceId' => $message->invoiceId,
                'provider' => $provider->value,
                'error' => $e->getMessage(),
            ]);

            $submission->setStatus(EInvoiceSubmissionStatus::ERROR);
            $submission->setErrorMessage($e->getMessage());
            $this->entityManager->flush();
        }
    }
}
