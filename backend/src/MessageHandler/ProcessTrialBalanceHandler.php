<?php

namespace App\MessageHandler;

use App\Entity\TrialBalanceRow;
use App\Message\ProcessTrialBalanceMessage;
use App\Repository\TrialBalanceRepository;
use App\Service\Balance\TrialBalancePdfParser;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ProcessTrialBalanceHandler
{
    public function __construct(
        private readonly TrialBalanceRepository $trialBalanceRepository,
        private readonly TrialBalancePdfParser $pdfParser,
        private readonly EntityManagerInterface $entityManager,
        private readonly FilesystemOperator $defaultStorage,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ProcessTrialBalanceMessage $message): void
    {
        $trialBalance = $this->trialBalanceRepository->find($message->trialBalanceId);
        if (!$trialBalance) {
            $this->logger->warning('TrialBalance not found for processing', ['id' => $message->trialBalanceId]);
            return;
        }

        if ($trialBalance->getStatus() !== 'pending') {
            $this->logger->warning('TrialBalance not in pending status', [
                'id' => $message->trialBalanceId,
                'status' => $trialBalance->getStatus(),
            ]);
            return;
        }

        $trialBalance->setStatus('processing');
        $this->entityManager->flush();

        try {
            $pdfContent = $this->defaultStorage->read($trialBalance->getStoragePath());
            $parsed = $this->pdfParser->parse($pdfContent);

            $this->logger->info('TrialBalance PDF parsed', [
                'id' => $message->trialBalanceId,
                'year' => $parsed->year,
                'month' => $parsed->month,
                'sourceSoftware' => $parsed->sourceSoftware,
                'rowCount' => count($parsed->rows),
            ]);

            // Update period info if detected from PDF
            if ($parsed->year !== null && $trialBalance->getYear() === 0) {
                $trialBalance->setYear($parsed->year);
            }
            if ($parsed->month !== null && $trialBalance->getMonth() === 0) {
                $trialBalance->setMonth($parsed->month);
            }
            if ($parsed->sourceSoftware !== null) {
                $trialBalance->setSourceSoftware($parsed->sourceSoftware);
            }

            // Clear existing rows using DQL bulk delete (avoids FK constraint issues with lazy collections)
            $this->entityManager->createQuery('DELETE FROM App\Entity\TrialBalanceRow r WHERE r.trialBalance = :tb')
                ->setParameter('tb', $trialBalance)
                ->execute();

            if (count($parsed->rows) === 0) {
                $this->logger->warning('TrialBalance PDF parsed 0 rows — check PDF format', [
                    'id' => $message->trialBalanceId,
                    'filename' => $trialBalance->getOriginalFilename(),
                ]);
            }

            // Persist new rows in batches
            $batchSize = 50;
            foreach ($parsed->rows as $i => $rowData) {
                $row = new TrialBalanceRow();
                $row->setTrialBalance($trialBalance);
                $row->setAccountCode($rowData['accountCode']);
                $row->setAccountName($rowData['accountName']);
                $row->setInitialDebit($rowData['initialDebit']);
                $row->setInitialCredit($rowData['initialCredit']);
                $row->setPreviousDebit($rowData['previousDebit']);
                $row->setPreviousCredit($rowData['previousCredit']);
                $row->setCurrentDebit($rowData['currentDebit']);
                $row->setCurrentCredit($rowData['currentCredit']);
                $row->setTotalDebit($rowData['totalDebit']);
                $row->setTotalCredit($rowData['totalCredit']);
                $row->setFinalDebit($rowData['finalDebit']);
                $row->setFinalCredit($rowData['finalCredit']);

                $this->entityManager->persist($row);

                if (($i + 1) % $batchSize === 0) {
                    $this->entityManager->flush();
                }
            }

            $trialBalance->setTotalAccounts(count($parsed->rows));
            $trialBalance->setStatus('completed');
            $trialBalance->setProcessedAt(new \DateTimeImmutable());
            $trialBalance->setError(null);
            $this->entityManager->flush();

            $this->logger->info('TrialBalance processed successfully', [
                'id' => $message->trialBalanceId,
                'totalAccounts' => count($parsed->rows),
            ]);
        } catch (\Throwable $e) {
            $trialBalance->setStatus('failed');
            $trialBalance->setError($e->getMessage());
            $this->entityManager->flush();

            $this->logger->error('TrialBalance processing failed', [
                'id' => $message->trialBalanceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
