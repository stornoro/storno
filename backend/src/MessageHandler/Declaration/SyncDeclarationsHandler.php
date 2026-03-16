<?php

namespace App\MessageHandler\Declaration;

use App\Entity\Company;
use App\Entity\TaxDeclaration;
use App\Enum\DeclarationStatus;
use App\Enum\DeclarationType;
use App\Event\Declaration\DeclarationSyncCompletedEvent;
use App\Message\Declaration\SyncDeclarationsMessage;
use App\Repository\TaxDeclarationRepository;
use App\Service\Anaf\AnafTokenResolver;
use App\Service\Declaration\AnafDeclarationClient;
use App\Service\Declaration\AnafTokenExpiredException;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AsMessageHandler]
final class SyncDeclarationsHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TaxDeclarationRepository $repository,
        private readonly AnafDeclarationClient $anafClient,
        private readonly AnafTokenResolver $anafTokenResolver,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly FilesystemOperator $defaultStorage,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(SyncDeclarationsMessage $message): void
    {
        $company = $this->entityManager->getRepository(Company::class)->find(
            Uuid::fromString($message->companyId)
        );

        if ($company === null) {
            $this->logger->warning('SyncDeclarationsHandler: Company not found.', [
                'companyId' => $message->companyId,
            ]);
            return;
        }

        $token = $this->anafTokenResolver->resolve($company);
        if ($token === null) {
            $this->logger->warning('SyncDeclarationsHandler: No valid ANAF token.', [
                'companyId' => $message->companyId,
            ]);
            return;
        }

        $cif = (string) $company->getCif();
        $stats = ['created' => 0, 'updated' => 0, 'recipisaDownloaded' => 0];

        try {
            // Fetch recent SPV messages filtered by CIF — retry once on auth failure
            try {
                $messagesResult = $this->anafClient->listMessagesByCif($token, $cif, 60);
            } catch (AnafTokenExpiredException) {
                $this->logger->info('SyncDeclarationsHandler: Token expired, re-resolving.');
                $token = $this->anafTokenResolver->resolve($company);
                if ($token === null) {
                    throw new \RuntimeException('No valid ANAF token available after refresh.');
                }
                $messagesResult = $this->anafClient->listMessagesByCif($token, $cif, 60);
            }
            $messages = $messagesResult['mesaje'] ?? [];

            foreach ($messages as $msg) {
                $tip = $msg['tip'] ?? '';

                // We're looking for recipisa messages
                if (stripos($tip, 'RECIPISA') === false && stripos($tip, 'recipisa') === false) {
                    continue;
                }

                $detalii = $msg['detalii'] ?? '';
                $parsed = $this->parseRecipisaDetails($detalii);
                if ($parsed === null) {
                    continue;
                }

                $declType = $this->resolveDeclarationType($parsed['type']);
                if ($declType === null) {
                    continue;
                }

                // Filter by requested year
                if ($parsed['year'] !== $message->year) {
                    continue;
                }

                $month = $parsed['month'] ?? 1;
                $existing = $this->repository->findByPeriod($company, $declType, $parsed['year'], $month);

                if (empty($existing)) {
                    // Create new declaration from ANAF sync
                    $declaration = new TaxDeclaration();
                    $declaration->setCompany($company);
                    $declaration->setType($declType);
                    $declaration->setYear($parsed['year']);
                    $declaration->setMonth($month);
                    $declaration->setPeriodType($declType->periodType());
                    $declaration->setStatus(DeclarationStatus::ACCEPTED);
                    $declaration->setMetadata([
                        'source' => 'anaf_sync',
                        'anafMessageId' => $msg['id'] ?? null,
                    ]);

                    $this->entityManager->persist($declaration);
                    $stats['created']++;

                    // Download recipisa
                    $this->tryDownloadRecipisa($declaration, $msg, $token, $company, $stats);
                } else {
                    // Update existing declarations
                    foreach ($existing as $declaration) {
                        $updated = false;

                        // Update in-flight declarations to ACCEPTED
                        if (in_array($declaration->getStatus(), [DeclarationStatus::SUBMITTED, DeclarationStatus::PROCESSING], true)) {
                            $declaration->setStatus(DeclarationStatus::ACCEPTED);
                            $declaration->setMetadata(array_merge($declaration->getMetadata() ?? [], [
                                'syncedFromAnaf' => true,
                                'anafMessageId' => $msg['id'] ?? null,
                            ]));
                            $updated = true;
                            $stats['updated']++;
                        }

                        // Download recipisa if missing
                        if ($declaration->getRecipisaPath() === null) {
                            $this->tryDownloadRecipisa($declaration, $msg, $token, $company, $stats);
                            $updated = true;
                        }

                        if ($updated) {
                            break; // Only update the most recent match
                        }
                    }
                }
            }

            $this->entityManager->flush();

            $this->eventDispatcher->dispatch(new DeclarationSyncCompletedEvent($company, $stats));
        } catch (\Throwable $e) {
            $this->logger->error('SyncDeclarationsHandler: Sync failed.', [
                'companyId' => $message->companyId,
                'year' => $message->year,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Parse recipisa details string.
     * Format: "recipisa pentru CIF 8000000000, tip D112, numar_inregistrare ..., perioada raportare 11.2017"
     */
    private function parseRecipisaDetails(string $detalii): ?array
    {
        $result = [];

        // Extract declaration type (e.g., D112, D394)
        if (preg_match('/tip\s+(D\d+)/i', $detalii, $m)) {
            $result['type'] = $m[1];
        } else {
            return null;
        }

        // Extract reporting period (e.g., "11.2017" or "2017")
        if (preg_match('/perioada\s+raportare\s+(\d{1,2})\.(\d{4})/i', $detalii, $m)) {
            $result['month'] = (int) $m[1];
            $result['year'] = (int) $m[2];
        } elseif (preg_match('/perioada\s+raportare\s+(\d{4})/i', $detalii, $m)) {
            $result['year'] = (int) $m[1];
            $result['month'] = 1;
        } else {
            return null;
        }

        return $result;
    }

    private function resolveDeclarationType(string $anafType): ?DeclarationType
    {
        $normalized = strtolower($anafType);
        // ANAF uses "D394", our enum uses "d394"
        if (!str_starts_with($normalized, 'd')) {
            $normalized = 'd' . $normalized;
        }

        return DeclarationType::tryFrom($normalized);
    }

    private function tryDownloadRecipisa(
        TaxDeclaration $declaration,
        array $msg,
        string $token,
        Company $company,
        array &$stats,
    ): void {
        $downloadId = $msg['id_descarcare'] ?? $msg['id'] ?? null;
        if ($downloadId === null) {
            return;
        }

        try {
            $recipisa = $this->anafClient->downloadRecipisa((string) $downloadId, $token);
            $recipisaPath = sprintf(
                'declarations/%s/%s/%s_recipisa.pdf',
                $company->getId(),
                $declaration->getType()->value,
                $declaration->getId()
            );
            $this->defaultStorage->write($recipisaPath, $recipisa);
            $declaration->setRecipisaPath($recipisaPath);
            $stats['recipisaDownloaded']++;
        } catch (\Throwable $e) {
            $this->logger->warning('SyncDeclarationsHandler: Failed to download recipisa.', [
                'messageId' => $downloadId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
