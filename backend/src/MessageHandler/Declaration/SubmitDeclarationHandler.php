<?php

namespace App\MessageHandler\Declaration;

use App\Entity\TaxDeclaration;
use App\Enum\DeclarationStatus;
use App\Event\Declaration\DeclarationSubmittedEvent;
use App\Message\Declaration\CheckDeclarationStatusMessage;
use App\Message\Declaration\SubmitDeclarationMessage;
use App\Service\Anaf\AnafTokenResolver;
use App\Service\Declaration\AnafDeclarationClient;
use App\Service\Declaration\AnafTokenExpiredException;
use App\Service\Declaration\DeclarationXmlGeneratorInterface;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AsMessageHandler]
final class SubmitDeclarationHandler
{
    /** @var array<string, DeclarationXmlGeneratorInterface> */
    private array $generators = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AnafDeclarationClient $anafClient,
        private readonly AnafTokenResolver $anafTokenResolver,
        private readonly MessageBusInterface $messageBus,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly FilesystemOperator $defaultStorage,
        private readonly LoggerInterface $logger,
        #[TaggedIterator('app.declaration_xml_generator')]
        iterable $xmlGenerators,
    ) {
        foreach ($xmlGenerators as $generator) {
            $this->generators[$generator->supports()] = $generator;
        }
    }

    public function __invoke(SubmitDeclarationMessage $message): void
    {
        $declaration = $this->entityManager->getRepository(TaxDeclaration::class)->find(
            Uuid::fromString($message->declarationId)
        );

        if ($declaration === null) {
            $this->logger->warning('SubmitDeclarationHandler: Declaration not found.', [
                'declarationId' => $message->declarationId,
            ]);
            return;
        }

        if ($declaration->getStatus()->isTerminal()) {
            return;
        }

        // Prevent duplicate upload: if already submitted/processing with an upload ID, skip
        if ($declaration->getAnafUploadId() !== null
            && in_array($declaration->getStatus(), [DeclarationStatus::SUBMITTED, DeclarationStatus::PROCESSING], true)
        ) {
            $this->logger->info('SubmitDeclarationHandler: Declaration already uploaded to ANAF, skipping.', [
                'declarationId' => $message->declarationId,
                'anafUploadId' => $declaration->getAnafUploadId(),
            ]);
            return;
        }

        $type = $declaration->getType()->value;
        $generator = $this->generators[$type] ?? null;

        if ($generator === null) {
            $declaration->setStatus(DeclarationStatus::ERROR);
            $declaration->setErrorMessage(sprintf('No XML generator found for type: %s', $type));
            $this->entityManager->flush();
            return;
        }

        try {
            // Generate XML
            $xml = $generator->generate($declaration);

            // Store XML
            $xmlPath = sprintf(
                'declarations/%s/%s/%s.xml',
                $declaration->getCompany()->getId(),
                $declaration->getType()->value,
                $declaration->getId()
            );
            $this->defaultStorage->write($xmlPath, $xml);
            $declaration->setXmlPath($xmlPath);

            // Get ANAF token
            $company = $declaration->getCompany();
            $anafToken = $this->anafTokenResolver->resolveEntity($company);

            if ($anafToken === null) {
                $declaration->setStatus(DeclarationStatus::ERROR);
                $declaration->setErrorMessage('No valid ANAF token available for this company.');
                $this->entityManager->flush();
                return;
            }

            $token = $anafToken->getToken();

            // Upload to ANAF — retry once with refreshed token on auth failure
            try {
                $result = $this->anafClient->upload($xml, (string) $company->getCif(), $token, $type);
            } catch (AnafTokenExpiredException) {
                $this->logger->info('SubmitDeclarationHandler: Token expired, re-resolving.', [
                    'declarationId' => $message->declarationId,
                ]);
                $anafToken = $this->anafTokenResolver->resolveEntity($company);
                if ($anafToken === null) {
                    throw new \RuntimeException('No valid ANAF token available after refresh.');
                }
                $token = $anafToken->getToken();
                $result = $this->anafClient->upload($xml, (string) $company->getCif(), $token, $type);
            }

            $uploadId = $result['id_solicitare'] ?? $result['index_incarcare'] ?? $result['id_incarcare'] ?? null;
            if ($uploadId) {
                $declaration->setAnafUploadId((string) $uploadId);
            }

            $declaration->setStatus(DeclarationStatus::PROCESSING);
            $declaration->setMetadata(array_merge($declaration->getMetadata() ?? [], [
                'uploadResult' => $result,
            ]));
            $this->entityManager->flush();

            $this->eventDispatcher->dispatch(new DeclarationSubmittedEvent($declaration));

            // Dispatch status check with delay
            if ($uploadId) {
                $this->messageBus->dispatch(
                    new CheckDeclarationStatusMessage(
                        declarationId: $message->declarationId,
                    )
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('SubmitDeclarationHandler: Submission failed.', [
                'declarationId' => $message->declarationId,
                'error' => $e->getMessage(),
            ]);

            $declaration->setStatus(DeclarationStatus::ERROR);
            $declaration->setErrorMessage($e->getMessage());
            $this->entityManager->flush();
        }
    }
}
