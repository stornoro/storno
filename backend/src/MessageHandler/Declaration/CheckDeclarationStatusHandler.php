<?php

namespace App\MessageHandler\Declaration;

use App\Entity\TaxDeclaration;
use App\Enum\DeclarationStatus;
use App\Event\Declaration\DeclarationAcceptedEvent;
use App\Event\Declaration\DeclarationRejectedEvent;
use App\Message\Declaration\CheckDeclarationStatusMessage;
use App\Service\Anaf\AnafTokenResolver;
use App\Service\Declaration\AnafDeclarationClient;
use App\Service\Declaration\AnafTokenExpiredException;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AsMessageHandler]
final class CheckDeclarationStatusHandler
{
    private const MAX_ATTEMPTS = 10;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AnafDeclarationClient $anafClient,
        private readonly AnafTokenResolver $anafTokenResolver,
        private readonly MessageBusInterface $messageBus,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly FilesystemOperator $defaultStorage,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(CheckDeclarationStatusMessage $message): void
    {
        $declaration = $this->entityManager->getRepository(TaxDeclaration::class)->find(
            Uuid::fromString($message->declarationId)
        );

        if ($declaration === null) {
            $this->logger->warning('CheckDeclarationStatusHandler: Declaration not found.', [
                'declarationId' => $message->declarationId,
            ]);
            return;
        }

        if ($declaration->getStatus()->isTerminal()) {
            return;
        }

        if ($declaration->getAnafUploadId() === null) {
            $declaration->setStatus(DeclarationStatus::ACCEPTED);
            $declaration->setMetadata(array_merge($declaration->getMetadata() ?? [], [
                'note' => 'No ANAF upload ID — marked as accepted.',
            ]));
            $this->entityManager->flush();
            return;
        }

        if ($message->attempt >= self::MAX_ATTEMPTS) {
            $declaration->setStatus(DeclarationStatus::ERROR);
            $declaration->setErrorMessage('Max status check attempts exceeded.');
            $this->entityManager->flush();
            return;
        }

        try {
            $company = $declaration->getCompany();
            [$token, $anafToken] = $this->resolveTokenWithRetry($company);

            $result = $this->anafClient->checkStatus($declaration->getAnafUploadId(), $token, $anafToken);

            $stare = $result['stare'] ?? $result['Stare'] ?? null;

            // D112 fallback: if SPV has no status yet, try the epatrim endpoint
            if ($stare === null && $declaration->getType()->value === 'd112') {
                try {
                    $d112Result = $this->anafClient->checkD112Status($token, $anafToken);
                    $d112Stare = $d112Result['stare'] ?? $d112Result['Stare'] ?? null;
                    if ($d112Stare !== null) {
                        $result = $d112Result;
                        $stare = $d112Stare;
                        $result['_source'] = 'epatrim_d112';
                    }
                } catch (\Throwable $e) {
                    $this->logger->info('D112 epatrim fallback unavailable.', [
                        'declarationId' => $message->declarationId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($stare === 'ok' || $stare === '1') {
                $declaration->setStatus(DeclarationStatus::ACCEPTED);
                $declaration->setMetadata(array_merge($declaration->getMetadata() ?? [], [
                    'statusResult' => $result,
                ]));

                // Try to download recipisa
                $this->tryDownloadRecipisa($declaration, $result, $token, $company, $anafToken);

                $this->entityManager->flush();
                $this->eventDispatcher->dispatch(new DeclarationAcceptedEvent($declaration));
            } elseif ($stare === 'nok' || $stare === '2') {
                $errorMessage = $result['Errors'] ?? $result['eroare'] ?? 'Declaration rejected by ANAF.';
                if (is_array($errorMessage)) {
                    $errorMessage = implode('; ', $errorMessage);
                }

                $declaration->setStatus(DeclarationStatus::REJECTED);
                $declaration->setErrorMessage($errorMessage);
                $declaration->setMetadata(array_merge($declaration->getMetadata() ?? [], [
                    'statusResult' => $result,
                ]));
                $this->entityManager->flush();
                $this->eventDispatcher->dispatch(new DeclarationRejectedEvent($declaration));
            } else {
                // Still processing — retry
                $this->messageBus->dispatch(
                    new CheckDeclarationStatusMessage(
                        declarationId: $message->declarationId,
                        attempt: $message->attempt + 1,
                    )
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('CheckDeclarationStatusHandler: Status check failed.', [
                'declarationId' => $message->declarationId,
                'error' => $e->getMessage(),
            ]);

            if ($message->attempt < self::MAX_ATTEMPTS - 1) {
                $this->messageBus->dispatch(
                    new CheckDeclarationStatusMessage(
                        declarationId: $message->declarationId,
                        attempt: $message->attempt + 1,
                    )
                );
            } else {
                $declaration->setStatus(DeclarationStatus::ERROR);
                $declaration->setErrorMessage($e->getMessage());
                $this->entityManager->flush();
            }
        }
    }

    /**
     * Resolve token entity, retrying once with a forced refresh on 401/403.
     *
     * @return array{0: string, 1: \App\Entity\AnafToken}
     */
    private function resolveTokenWithRetry(\App\Entity\Company $company): array
    {
        $anafToken = $this->anafTokenResolver->resolveEntity($company);
        if ($anafToken === null) {
            throw new \RuntimeException('No valid ANAF token available.');
        }

        return [$anafToken->getToken(), $anafToken];
    }

    private function tryDownloadRecipisa(
        TaxDeclaration $declaration,
        array $result,
        string $token,
        \App\Entity\Company $company,
        ?\App\Entity\AnafToken $anafToken = null,
    ): void {
        // For D112 via epatrim, try the filename-based download
        if (($result['_source'] ?? null) === 'epatrim_d112') {
            $filename = $result['numefisier'] ?? $result['numeFisier'] ?? null;
            if ($filename) {
                try {
                    $recipisa = $this->anafClient->downloadD112Recipisa($filename, $token, $anafToken);
                    $recipisaPath = sprintf(
                        'declarations/%s/%s/%s_recipisa.pdf',
                        $company->getId(),
                        $declaration->getType()->value,
                        $declaration->getId()
                    );
                    $this->defaultStorage->write($recipisaPath, $recipisa);
                    $declaration->setRecipisaPath($recipisaPath);
                    return;
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to download D112 recipisa via epatrim.', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Standard SPV download
        $downloadId = $result['id_descarcare'] ?? $result['id'] ?? null;
        if ($downloadId) {
            try {
                $recipisa = $this->anafClient->downloadRecipisa((string) $downloadId, $token, $anafToken);
                $recipisaPath = sprintf(
                    'declarations/%s/%s/%s_recipisa.pdf',
                    $company->getId(),
                    $declaration->getType()->value,
                    $declaration->getId()
                );
                $this->defaultStorage->write($recipisaPath, $recipisa);
                $declaration->setRecipisaPath($recipisaPath);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to download recipisa.', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
