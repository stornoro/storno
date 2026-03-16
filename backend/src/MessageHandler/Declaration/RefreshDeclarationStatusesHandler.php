<?php

namespace App\MessageHandler\Declaration;

use App\Entity\Company;
use App\Entity\TaxDeclaration;
use App\Enum\DeclarationStatus;
use App\Event\Declaration\DeclarationAcceptedEvent;
use App\Event\Declaration\DeclarationRejectedEvent;
use App\Message\Declaration\RefreshDeclarationStatusesMessage;
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
final class RefreshDeclarationStatusesHandler
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

    public function __invoke(RefreshDeclarationStatusesMessage $message): void
    {
        $company = $this->entityManager->getRepository(Company::class)->find(
            Uuid::fromString($message->companyId)
        );

        if ($company === null) {
            $this->logger->warning('RefreshDeclarationStatusesHandler: Company not found.', [
                'companyId' => $message->companyId,
            ]);
            return;
        }

        // Find all in-flight declarations
        $inFlight = $this->repository->findByCompanyAndStatuses($company, [
            DeclarationStatus::SUBMITTED,
            DeclarationStatus::PROCESSING,
        ]);

        if (empty($inFlight)) {
            return;
        }

        $token = $this->anafTokenResolver->resolve($company);
        if ($token === null) {
            $this->logger->warning('RefreshDeclarationStatusesHandler: No valid ANAF token.', [
                'companyId' => $message->companyId,
            ]);
            return;
        }

        $cif = (string) $company->getCif();

        try {
            // Retry once on auth failure with a fresh token
            try {
                $messagesResult = $this->anafClient->listMessagesByCif($token, $cif, 60);
            } catch (AnafTokenExpiredException) {
                $this->logger->info('RefreshDeclarationStatusesHandler: Token expired, re-resolving.');
                $token = $this->anafTokenResolver->resolve($company);
                if ($token === null) {
                    throw new \RuntimeException('No valid ANAF token available after refresh.');
                }
                $messagesResult = $this->anafClient->listMessagesByCif($token, $cif, 60);
            }
            $messages = $messagesResult['mesaje'] ?? [];

            // Index messages by id_solicitare for fast lookup
            $messagesByUploadId = [];
            foreach ($messages as $msg) {
                $idSolicitare = $msg['id_solicitare'] ?? null;
                if ($idSolicitare !== null) {
                    $messagesByUploadId[(string) $idSolicitare] = $msg;
                }
            }

            foreach ($inFlight as $declaration) {
                $uploadId = $declaration->getAnafUploadId();
                if ($uploadId === null) {
                    continue;
                }

                $msg = $messagesByUploadId[$uploadId] ?? null;
                if ($msg === null) {
                    continue;
                }

                $stare = $msg['stare'] ?? $msg['Stare'] ?? null;

                if ($stare === 'ok' || $stare === '1') {
                    $declaration->setStatus(DeclarationStatus::ACCEPTED);
                    $declaration->setMetadata(array_merge($declaration->getMetadata() ?? [], [
                        'statusRefresh' => $msg,
                    ]));

                    // Download recipisa if available
                    $downloadId = $msg['id_descarcare'] ?? $msg['id'] ?? null;
                    if ($downloadId && $declaration->getRecipisaPath() === null) {
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
                        } catch (\Throwable $e) {
                            $this->logger->warning('RefreshDeclarationStatusesHandler: Failed to download recipisa.', [
                                'declarationId' => (string) $declaration->getId(),
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    $this->entityManager->flush();
                    $this->eventDispatcher->dispatch(new DeclarationAcceptedEvent($declaration));
                } elseif ($stare === 'nok' || $stare === '2') {
                    $errorMessage = $msg['Errors'] ?? $msg['eroare'] ?? 'Declaration rejected by ANAF.';
                    if (is_array($errorMessage)) {
                        $errorMessage = implode('; ', $errorMessage);
                    }

                    $declaration->setStatus(DeclarationStatus::REJECTED);
                    $declaration->setErrorMessage($errorMessage);
                    $declaration->setMetadata(array_merge($declaration->getMetadata() ?? [], [
                        'statusRefresh' => $msg,
                    ]));

                    $this->entityManager->flush();
                    $this->eventDispatcher->dispatch(new DeclarationRejectedEvent($declaration));
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('RefreshDeclarationStatusesHandler: Refresh failed.', [
                'companyId' => $message->companyId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
