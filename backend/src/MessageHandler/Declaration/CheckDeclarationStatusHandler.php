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
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AsMessageHandler]
final class CheckDeclarationStatusHandler
{
    private const MAX_ATTEMPTS = 10;
    /** ANAF indexes a filing within minutes; ask again every 5 minutes instead of at once. */
    private const RETRY_DELAY_MS = 300_000;

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

            // Filed on the e-guvernare portal (agent + certificate): the public StareD112
            // page answers by index + CUI without any token.
            $meta = $declaration->getMetadata() ?? [];
            $viaPortal = ($meta['uploadResult']['source'] ?? null) === 'was6dus'
                || ($meta['submittedViaAgent'] ?? false) === true
                || ($meta['filedExternally'] ?? false) === true;
            if ($viaPortal) {
                // ghiseu: the number is the registration number from the counter, not an upload index
                $portal = $this->anafClient->checkPortalStatus($declaration->getAnafUploadId(), $company->getCif(), ($meta['ghiseu'] ?? false) === true);
                $this->applyPortalStatus($declaration, $portal, $message, $company);

                return;
            }

            $token = $this->resolveToken($company);

            $result = $this->anafClient->checkStatus($declaration->getAnafUploadId(), $token);

            $stare = $result['stare'] ?? $result['Stare'] ?? null;

            // D112 fallback: if SPV has no status yet, try the epatrim endpoint
            if ($stare === null && $declaration->getType()->value === 'd112') {
                try {
                    $d112Result = $this->anafClient->checkD112Status($token);
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
                $this->tryDownloadRecipisa($declaration, $result, $token, $company);

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
                $this->messageBus->dispatch(new CheckDeclarationStatusMessage(declarationId: $message->declarationId, attempt: $message->attempt + 1), [new DelayStamp(self::RETRY_DELAY_MS)]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('CheckDeclarationStatusHandler: Status check failed.', [
                'declarationId' => $message->declarationId,
                'error' => $e->getMessage(),
            ]);

            if ($message->attempt < self::MAX_ATTEMPTS - 1) {
                $this->messageBus->dispatch(new CheckDeclarationStatusMessage(declarationId: $message->declarationId, attempt: $message->attempt + 1), [new DelayStamp(self::RETRY_DELAY_MS)]);
            } else {
                $declaration->setStatus(DeclarationStatus::ERROR);
                $declaration->setErrorMessage($e->getMessage());
                $this->entityManager->flush();
            }
        }
    }

    /** @param array{stare: string, text: ?string, index: string, raw: string} $portal */
    private function applyPortalStatus(TaxDeclaration $declaration, array $portal, CheckDeclarationStatusMessage $message, \App\Entity\Company $company): void
    {
        $result = ['_source' => 'stared112', 'stare' => $portal['stare'], 'text' => $portal['text'], 'index' => $portal['index']];

        if ($portal['stare'] === 'ok') {
            $recipisaErrors = null;
            try {
                $recipisa = $this->anafClient->downloadPortalRecipisa($portal['index']);
                if ($recipisa !== '') {
                    $recipisaPath = sprintf('declarations/%s/%s/%s_recipisa.pdf', $company->getId(), $declaration->getType()->value, $declaration->getId());
                    $this->defaultStorage->write($recipisaPath, $recipisa);
                    $declaration->setRecipisaPath($recipisaPath);
                    $recipisaErrors = self::errorsInRecipisa($recipisa);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to download portal recipisa.', ['error' => $e->getMessage()]);
            }

            // "Documentul este valid" on the portal only says the file was read; the recipisa is
            // where ANAF reports whether the declaration entered its records (e.g. R_NO_INIT_DEC).
            if ($recipisaErrors !== null) {
                $result['recipisaErrors'] = $recipisaErrors;
                $declaration->setStatus(DeclarationStatus::REJECTED);
                $declaration->setErrorMessage('ANAF: ' . mb_substr($recipisaErrors, 0, 500));
                $declaration->setMetadata(array_merge($declaration->getMetadata() ?? [], ['statusResult' => $result]));
                $this->entityManager->flush();
                $this->eventDispatcher->dispatch(new DeclarationRejectedEvent($declaration));

                return;
            }

            $declaration->setStatus(DeclarationStatus::ACCEPTED);
            $declaration->setMetadata(array_merge($declaration->getMetadata() ?? [], ['statusResult' => $result]));
            $this->entityManager->flush();
            $this->eventDispatcher->dispatch(new DeclarationAcceptedEvent($declaration));

            return;
        }

        if ($portal['stare'] === 'nok') {
            $declaration->setStatus(DeclarationStatus::REJECTED);
            $declaration->setErrorMessage('ANAF: ' . ($portal['text'] ?? 'Documentul are erori de validare.') . ' Detaliile sunt in recipisa.');
            $declaration->setMetadata(array_merge($declaration->getMetadata() ?? [], ['statusResult' => $result]));
            try {
                $recipisa = $this->anafClient->downloadPortalRecipisa($portal['index']);
                if ($recipisa !== '') {
                    $recipisaPath = sprintf('declarations/%s/%s/%s_recipisa.pdf', $company->getId(), $declaration->getType()->value, $declaration->getId());
                    $this->defaultStorage->write($recipisaPath, $recipisa);
                    $declaration->setRecipisaPath($recipisaPath);
                }
            } catch (\Throwable) {
                // recipisa is optional here
            }
            $this->entityManager->flush();
            $this->eventDispatcher->dispatch(new DeclarationRejectedEvent($declaration));

            return;
        }

        // processing / not yet indexed: retry later
        $this->messageBus->dispatch(new CheckDeclarationStatusMessage(declarationId: $message->declarationId, attempt: $message->attempt + 1), [new DelayStamp(self::RETRY_DELAY_MS)]);
    }

    private function resolveToken(\App\Entity\Company $company): string
    {
        $anafToken = $this->anafTokenResolver->resolveEntity($company);
        if ($anafToken === null) {
            throw new \RuntimeException('No valid ANAF token available.');
        }

        return $anafToken->getToken();
    }

    private function tryDownloadRecipisa(
        TaxDeclaration $declaration,
        array $result,
        string $token,
        \App\Entity\Company $company,
    ): void {
        // For D112 via epatrim, try the filename-based download
        if (($result['_source'] ?? null) === 'epatrim_d112') {
            $filename = $result['numefisier'] ?? $result['numeFisier'] ?? null;
            if ($filename) {
                try {
                    $recipisa = $this->anafClient->downloadD112Recipisa($filename, $token);
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
                $this->logger->warning('Failed to download recipisa.', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * The errors a recipisa lists, or null when it confirms the filing. ANAF writes
     * "Au fost identificat(e) următoarele ERORI:" followed by the rules that failed, while an
     * accepted one says "Nu există erori de validare".
     */
    public static function errorsInRecipisa(string $pdf): ?string
    {
        try {
            $text = (new \Smalot\PdfParser\Parser())->parseContent($pdf)->getText();
        } catch (\Throwable) {
            return null;
        }
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        $normalized = mb_strtolower(strtr($text, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']));
        if (!str_contains($normalized, 'erori') || str_contains($normalized, 'nu exista erori')) {
            return null;
        }
        if (preg_match('/(?:ERORI:?)(.*)$/u', $text, $m) === 1) {
            $details = trim($m[1]);
            if ($details !== '') {
                return mb_substr($details, 0, 1000);
            }
        }

        return 'Recipisa listează erori de prelucrare; deschide-o pentru detalii.';
    }
}
