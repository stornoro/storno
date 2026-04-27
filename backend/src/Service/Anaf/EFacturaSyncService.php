<?php

namespace App\Service\Anaf;

use App\DTO\Sync\ParsedAttachment;
use App\DTO\Sync\ParsedInvoice;
use App\DTO\Sync\ParsedInvoiceLine;
use App\DTO\Sync\ParsedParty;
use App\DTO\Sync\SyncResult;
use App\Entity\BankAccount;
use App\Entity\Client;
use App\Entity\Company;
use App\Entity\DocumentEvent;
use App\Entity\EFacturaMessage;
use App\Entity\Invoice;
use App\Entity\InvoiceAttachment;
use App\Entity\InvoiceLine;
use App\Entity\Product;
use App\Entity\Supplier;
use App\Enum\DocumentStatus;
use App\Enum\MessageKey;
use App\Enum\InvoiceDirection;
use App\Repository\BankAccountRepository;
use App\Repository\ClientRepository;
use App\Repository\EFacturaMessageRepository;
use App\Repository\InvoiceRepository;
use App\Repository\OrganizationMembershipRepository;
use App\Repository\ProductRepository;
use App\Repository\SupplierRepository;
use App\Enum\DocumentType;
use App\Manager\DocumentSeriesManager;
use App\Service\Centrifugo\CentrifugoService;
use App\Service\NotificationService;
use App\Service\Webhook\WebhookDispatcher;
use App\Util\AddressNormalizer;
use App\Utils\Functions;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use App\Exception\AnafRateLimitException;
use Psr\Log\LoggerInterface;

class EFacturaSyncService
{
    private const BATCH_SIZE = 10;
    private const CHANNEL_PREFIX = 'invoices:company_';

    private EntityManagerInterface $entityManager;

    /** @var array<string, Client> In-memory cache for unflushed clients (keyed by companyId:cif) */
    private array $pendingClients = [];

    /** @var array<string, Supplier> In-memory cache for unflushed suppliers (keyed by companyId:cif) */
    private array $pendingSuppliers = [];

    public function __construct(
        private readonly EFacturaClient $eFacturaClient,
        private readonly EFacturaXmlParser $xmlParser,
        private readonly AnafTokenResolver $tokenResolver,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly ClientRepository $clientRepository,
        private readonly ProductRepository $productRepository,
        private readonly SupplierRepository $supplierRepository,
        private readonly BankAccountRepository $bankAccountRepository,
        private readonly EFacturaMessageRepository $eFacturaMessageRepository,
        EntityManagerInterface $entityManager,
        private readonly InvoiceArchiver $invoiceArchiver,
        private readonly CentrifugoService $centrifugo,
        private readonly LoggerInterface $logger,
        private readonly NotificationService $notificationService,
        private readonly OrganizationMembershipRepository $membershipRepository,
        private readonly SeriesDetector $seriesDetector,
        private readonly DocumentSeriesManager $documentSeriesManager,
        private readonly ManagerRegistry $managerRegistry,
        private readonly WebhookDispatcher $webhookDispatcher,
    ) {
        $this->entityManager = $entityManager;
    }

    public function syncCompany(Company $company, ?int $daysOverride = null): SyncResult
    {
        $result = new SyncResult();
        $cif = (string) $company->getCif();

        $channel = self::CHANNEL_PREFIX . $company->getId()->toRfc4122();

        // Short-circuit if we already know ANAF denies SPV access for this CIF.
        // Avoids daily retries and duplicate notifications until the user
        // re-links an ANAF token or manually triggers sync.
        if ($company->getSpvAccessError() !== null) {
            $this->logger->info('Skipping sync — SPV access previously denied', [
                'cif' => $cif,
                'deniedAt' => $company->getSpvAccessErrorAt()?->format('c'),
            ]);
            $this->centrifugo->publish($channel, [
                'type' => 'sync.skipped',
                'reason' => 'spv_access_denied',
                'error' => $company->getSpvAccessError(),
            ]);
            return $result;
        }

        $token = $this->tokenResolver->resolve($company);
        if (!$token) {
            $result->addError('No valid ANAF token found for company ' . $company->getName());
            $this->notifySyncErrors($company, $result->getErrors());
            $this->centrifugo->publish($channel, [
                'type' => 'sync.error',
                'errors' => $result->getErrors(),
            ]);
            return $result;
        }

        $days = $daysOverride ?? $this->calculateDaysBack($company);

        $this->logger->info('Starting e-Factura sync', [
            'company' => $company->getName(),
            'cif' => $cif,
            'days' => $days,
        ]);
        try {
            $messagesData = $this->eFacturaClient->listMessages($cif, $token, $days);
        } catch (AnafRateLimitException $e) {
            $result->addError('ANAF rate limit reached: ' . $e->getMessage());
            $this->logger->warning('ANAF rate limit hit during listMessages', [
                'cif' => $cif,
                'limit' => $e->limitName,
                'retryAfter' => $e->retryAfter,
            ]);
            $this->notifySyncErrors($company, $result->getErrors());
            $this->centrifugo->publish($channel, [
                'type' => 'sync.error',
                'errors' => $result->getErrors(),
            ]);
            return $result;
        } catch (\Throwable $e) {
            $result->addError('Failed to list messages from ANAF: ' . $e->getMessage());
            $this->logger->error('Failed to list ANAF messages', [
                'cif' => $cif,
                'error' => $e->getMessage(),
            ]);
            $this->notifySyncErrors($company, $result->getErrors());
            $this->centrifugo->publish($channel, [
                'type' => 'sync.error',
                'errors' => $result->getErrors(),
            ]);
            return $result;
        }

        // ANAF signals authorization problems via an `eroare` field in the
        // otherwise-successful JSON body. Persist the denial so subsequent
        // daily sync runs skip this company until the user fixes it.
        $anafError = $messagesData['eroare'] ?? null;
        if ($anafError !== null && $this->isSpvAccessDenied($anafError)) {
            $company->markSpvAccessDenied($anafError);
            $this->entityManager->flush();
            $result->addError($anafError);
            $this->notifySyncErrors($company, $result->getErrors());
            $this->centrifugo->publish($channel, [
                'type' => 'sync.error',
                'errors' => $result->getErrors(),
            ]);
            $this->logger->warning('ANAF denied SPV access — sync will be paused until re-authorized', [
                'cif' => $cif,
                'error' => $anafError,
            ]);
            return $result;
        }

        $messages = $messagesData['mesaje'] ?? [];
        $totalMessages = count($messages);

        if (empty($messages)) {
            $this->logger->info('No messages found', ['cif' => $cif]);
            $company->setLastSyncedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
            $this->centrifugo->publish($channel, [
                'type' => 'sync.completed',
                'stats' => $result->toArray(),
            ]);
            return $result;
        }

        $this->centrifugo->publish($channel, [
            'type' => 'sync.started',
            'total' => $totalMessages,
        ]);
        $this->webhookDispatcher->dispatchForCompany($company, 'sync.started', [
            'total' => $totalMessages,
        ]);

        // Batch idempotency: load all existing message IDs upfront
        $existingIds = $this->invoiceRepository->findExistingAnafMessageIds(
            array_map(fn($m) => (string) ($m['id'] ?? ''), $messages)
        );

        $batchCount = 0;
        $processedCount = 0;
        $companyId = $company->getId();
        foreach ($messages as $message) {
            $messageId = (string) ($message['id'] ?? '');
            $processed = $this->processMessage($message, $messageId, $company, $cif, $token, $existingIds, $result);
            $processedCount++;

            // Re-fetch company if EntityManager was reset during message processing
            if (!$this->entityManager->contains($company)) {
                $company = $this->entityManager->getReference(Company::class, $companyId);
            }

            // Always publish progress so the UI stays responsive (even for skipped messages)
            if ($processedCount % 5 === 0 || $processedCount === $totalMessages) {
                $this->centrifugo->publish($channel, [
                    'type' => 'sync.progress',
                    'processed' => $processedCount,
                    'total' => $totalMessages,
                    'stats' => $result->toArray(),
                ]);
            }

            if ($processed) {
                $batchCount++;

                if ($batchCount % self::BATCH_SIZE === 0) {
                    try {
                        $this->entityManager->flush();
                    } catch (\Throwable $e) {
                        $this->logger->error('Batch flush failed during sync, resetting EntityManager and continuing', [
                            'company' => $company->getId()->toRfc4122(),
                            'batchCount' => $batchCount,
                            'error' => $e->getMessage(),
                        ]);
                        $result->addError('Batch flush error: ' . $e->getMessage());
                        $this->resetEntityManager();
                    }
                    $this->entityManager->clear();
                    $this->documentSeriesManager->clearCache();
                    $this->pendingClients = [];
                    $this->pendingSuppliers = [];
                    // Re-fetch company reference after clear
                    $company = $this->entityManager->getReference(Company::class, $company->getId());
                }
            }
        }

        // Final flush for remaining entities
        try {
            $company->setLastSyncedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Final flush failed during sync, resetting EntityManager', [
                'company' => $company->getId()->toRfc4122(),
                'error' => $e->getMessage(),
            ]);
            $result->addError('Final flush error: ' . $e->getMessage());
            $this->resetEntityManager();
            $company = $this->entityManager->getReference(Company::class, $company->getId());

            // Retry setting lastSyncedAt with fresh EntityManager
            try {
                $company->setLastSyncedAt(new \DateTimeImmutable());
                $this->entityManager->flush();
            } catch (\Throwable) {
                // Non-critical, will be updated on next sync
            }
        }

        // Ensure default series exist for companies with no detected series
        try {
            $this->documentSeriesManager->ensureDefaultSeries($company);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error('ensureDefaultSeries failed during sync', [
                'company' => $company->getId()->toRfc4122(),
                'error' => $e->getMessage(),
            ]);
            $result->addError('Default series error: ' . $e->getMessage());
            $this->resetEntityManager();
        }

        $this->centrifugo->publish($channel, [
            'type' => 'sync.completed',
            'stats' => $result->toArray(),
        ]);
        $this->webhookDispatcher->dispatchForCompany($company, 'sync.completed', [
            'stats' => $result->toArray(),
        ]);

        $this->logger->info('Sync completed', [
            'company' => $company->getName(),
            'newInvoices' => $result->getNewInvoices(),
            'skipped' => $result->getSkippedDuplicates(),
            'errors' => count($result->getErrors()),
        ]);

        // Clear EM before notifications to avoid orphaned entity state from sync batches
        $companyId = $company->getId();
        $this->entityManager->clear();
        $this->pendingClients = [];
        $this->pendingSuppliers = [];

        if ($result->getNewInvoices() > 0 || $result->hasErrors()) {
            $company = $this->entityManager->getReference(Company::class, $companyId);
        }

        if ($result->getNewInvoices() > 0) {
            $this->notifyNewDocuments($company, $result->getNewInvoices(), $result->getNewInvoiceSummaries());
        }

        if ($result->hasErrors()) {
            $this->notifySyncErrors($company, $result->getErrors());
        }

        return $result;
    }

    private function processMessage(array $message, string $messageId, Company $company, string $cif, string $token, array &$existingIds, SyncResult $result): bool
    {
        $type = $message['tip'] ?? '';

        // Store all SPV messages for browsing
        $spvMessage = $this->storeSpvMessage($company, $message, $messageId, $type);

        // Only process FACTURA PRIMITA and FACTURA TRIMISA
        $direction = match ($type) {
            'FACTURA PRIMITA' => InvoiceDirection::INCOMING,
            'FACTURA TRIMISA' => InvoiceDirection::OUTGOING,
            default => null,
        };

        if ($direction === null) {
            // For ERORI FACTURA, download and parse the error content
            if ($type === 'ERORI FACTURA') {
                $this->processErrorMessage($spvMessage, $messageId, $company, $token, $result);
            }
            return false;
        }

        // Batch idempotency check — skip if invoice already exists
        if (isset($existingIds[$messageId])) {
            $result->incrementSkippedDuplicates();
            if ($spvMessage) {
                $spvMessage->setStatus('processed');
            }
            return false;
        }

        // Skip messages already processed or permanently failed (avoids re-downloading from ANAF)
        if ($spvMessage && in_array($spvMessage->getStatus(), ['processed', 'error'], true)) {
            $result->incrementSkippedDuplicates();
            return false;
        }

        try {
            $zipData = $this->eFacturaClient->download($messageId, $token);
            $unzipped = Functions::unzip($zipData);
            $xml = $unzipped['xml'];
            $signature = $unzipped['signature'];

            if (!$xml) {
                $result->addError("Failed to extract XML from message $messageId");
                if ($spvMessage) {
                    $spvMessage->setStatus('error');
                    $spvMessage->setErrorMessage('Failed to extract XML');
                }
                return false;
            }

            $parsed = $this->xmlParser->parse($xml);

            // Match outgoing invoices that were created locally and submitted to ANAF
            $existingInvoice = $this->findExistingLocalInvoice($message, $company, $direction, $parsed);
            if ($existingInvoice) {
                $this->mergeAnafDataIntoExisting($existingInvoice, $messageId, $signature, $message);
                $existingIds[$messageId] = true;
                if ($spvMessage) {
                    $spvMessage->setStatus('processed');
                    $spvMessage->setInvoice($existingInvoice);
                }
                try {
                    $this->invoiceArchiver->archive($existingInvoice, $xml, $signature);
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to archive matched invoice', [
                        'messageId' => $messageId,
                        'error' => $e->getMessage(),
                    ]);
                }
                $result->incrementSkippedDuplicates();
                return true;
            }

            $invoice = $this->createInvoiceFromParsed($parsed, $company, $direction, $messageId, $xml, $signature, $message, $result);
            $existingIds[$messageId] = true;

            // Auto-detect series from outgoing invoice numbers
            if ($direction === InvoiceDirection::OUTGOING && $parsed->number) {
                $detected = $this->seriesDetector->detect($parsed->number);
                if ($detected) {
                    $type = $parsed->documentType === DocumentType::CREDIT_NOTE ? 'credit_note' : 'invoice';
                    $upsertResult = $this->documentSeriesManager->upsertFromEfactura($company, $detected['prefix'], $detected['number'], $type);
                    if ($upsertResult['created']) {
                        $result->incrementNewSeries();
                    }
                }
            }

            if ($spvMessage) {
                $spvMessage->setStatus('processed');
                $spvMessage->setInvoice($invoice);
            }

            try {
                $this->invoiceArchiver->archive($invoice, $xml, $signature);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to archive invoice', [
                    'messageId' => $messageId,
                    'error' => $e->getMessage(),
                ]);
            }

            $result->recordNewInvoice($invoice);
            return true;
        } catch (AnafRateLimitException $e) {
            $result->addError("ANAF rate limit hit for message $messageId (retry after {$e->retryAfter}s)");
            if ($spvMessage) {
                $spvMessage->setStatus('error');
                $spvMessage->setErrorMessage('ANAF rate limit: ' . $e->getMessage());
            }
            $this->logger->warning('ANAF rate limit hit during download, skipping message', [
                'messageId' => $messageId,
                'limit' => $e->limitName,
                'retryAfter' => $e->retryAfter,
            ]);
            return false;
        } catch (\Throwable $e) {
            $result->addError("Error processing message $messageId: " . $e->getMessage());
            $this->logger->error('Error processing ANAF message', [
                'messageId' => $messageId,
                'error' => $e->getMessage(),
            ]);

            // Reset EntityManager if closed (e.g. deadlock), then re-fetch and mark message as error
            if (!$this->entityManager->isOpen()) {
                $this->resetEntityManager();
            }

            if ($spvMessage) {
                try {
                    $spvMessage = $this->entityManager->find(EFacturaMessage::class, $spvMessage->getId());
                    if ($spvMessage) {
                        $spvMessage->setStatus('error');
                        $spvMessage->setErrorMessage(mb_substr($e->getMessage(), 0, 500));
                        $this->entityManager->flush();
                    }
                } catch (\Throwable) {
                    // Non-critical — message will be retried on next sync
                }
            }

            return false;
        }
    }

    private function storeSpvMessage(Company $company, array $message, string $messageId, string $type): ?EFacturaMessage
    {
        // Check if message already exists
        $existing = $this->eFacturaMessageRepository->findByAnafMessageId($messageId);
        if ($existing) {
            return $existing;
        }

        $spvMessage = new EFacturaMessage();
        // Ensure company is managed in current EntityManager (may be detached after clear/reset)
        if (!$this->entityManager->contains($company)) {
            $company = $this->entityManager->getReference(Company::class, $company->getId());
        }
        $spvMessage->setCompany($company);
        $spvMessage->setAnafMessageId($messageId);
        $spvMessage->setMessageType($type);
        $spvMessage->setCif($message['cif'] ?? null);
        $spvMessage->setDetails($message['detalii'] ?? null);

        $payload = $message['detalii'] ?? '';
        if (preg_match('/id_incarcare=(\d+)/', $payload, $m)) {
            $spvMessage->setUploadId($m[1]);
        }

        $this->entityManager->persist($spvMessage);

        return $spvMessage;
    }

    private function createInvoiceFromParsed(
        ParsedInvoice $parsed,
        Company $company,
        InvoiceDirection $direction,
        string $messageId,
        string $rawXml,
        ?string $signatureContent,
        array $message,
        SyncResult $result,
    ): Invoice {
        $invoice = new Invoice();
        $invoice->setCompany($company);
        $invoice->setDirection($direction);
        $invoice->setAnafMessageId($messageId);
        $invoice->setDocumentType($parsed->documentType);
        $invoice->setStatus(DocumentStatus::SYNCED);
        $invoice->setNumber($parsed->number ?? 'N/A');
        $invoice->setCurrency($parsed->currency);
        $invoice->setSubtotal($parsed->subtotal);
        $invoice->setVatTotal($parsed->vatTotal);
        $invoice->setTotal($parsed->total);
        $invoice->setSyncedAt(new \DateTimeImmutable());

        if ($parsed->issueDate) {
            $invoice->setIssueDate(new \DateTime($parsed->issueDate));
        }
        if ($parsed->dueDate) {
            $invoice->setDueDate(new \DateTime($parsed->dueDate));
        }
        if ($parsed->notes) {
            $invoice->setNotes($parsed->notes);
        }
        if ($parsed->paymentTerms) {
            $invoice->setPaymentTerms($parsed->paymentTerms);
        }
        if ($parsed->deliveryLocation) {
            $invoice->setDeliveryLocation($parsed->deliveryLocation);
        }
        if ($parsed->projectReference) {
            $invoice->setProjectReference($parsed->projectReference);
        }
        if ($parsed->ublExtensions) {
            $invoice->setUblExtensions($parsed->ublExtensions);
        }

        // Extract upload ID from message details
        $payload = $message['detalii'] ?? '';
        if (preg_match('/id_incarcare=(\d+)/', $payload, $m)) {
            $invoice->setAnafUploadId($m[1]);
        }
        $invoice->setAnafDownloadId($messageId);

        // Set sender/receiver info
        if ($parsed->seller) {
            $invoice->setSenderCif($parsed->seller->cif);
            $invoice->setSenderName($parsed->seller->name);
        }
        if ($parsed->buyer) {
            $invoice->setReceiverCif($parsed->buyer->cif);
            $invoice->setReceiverName($parsed->buyer->name);
        }

        // Duplicate detection: same invoice number + supplier CIF + direction + company
        $supplierCif = $direction === InvoiceDirection::INCOMING
            ? $parsed->seller?->cif
            : $parsed->buyer?->cif;
        if ($parsed->number && $supplierCif) {
            $existingDuplicate = $this->invoiceRepository->findOneBy([
                'company' => $company,
                'number' => $parsed->number,
                'senderCif' => $parsed->seller?->cif,
                'direction' => $direction,
            ]);
            if ($existingDuplicate) {
                $invoice->setIsDuplicate(true);
            }
        }

        // Late submission detection: upload date - issue date > 5 calendar days
        $this->detectLateSubmission($invoice, $message);

        // OUTGOING: buyer is our client (companies by CIF, individuals by name)
        if ($direction === InvoiceDirection::OUTGOING && $parsed->buyer && ($parsed->buyer->cif || $parsed->buyer->name)) {
            $client = $this->findOrCreateClient($company, $parsed->buyer, $result);
            $invoice->setClient($client);
            $invoice->snapshotBuyer($client);
        }

        // INCOMING: seller is our supplier
        if ($direction === InvoiceDirection::INCOMING && $parsed->seller && $parsed->seller->cif) {
            $supplier = $this->findOrCreateSupplier($company, $parsed->seller, $result);
            $invoice->setSupplier($supplier);
        }

        // INCOMING: snapshot the buyer (our company) from the XML so the detail page
        // can show all parsed fields (address, bank, contact info, ...).
        if ($direction === InvoiceDirection::INCOMING && $parsed->buyer) {
            $invoice->setBuyerSnapshot($this->buildBuyerSnapshotFromParsed($parsed->buyer));
        }

        // Auto-extract bank accounts only from own company data (seller on outgoing invoices)
        if ($direction === InvoiceDirection::OUTGOING && $parsed->seller?->bankAccount) {
            $this->findOrCreateBankAccount($company, $parsed->seller->bankAccount, $parsed->seller->bankName, $parsed->currency);
        }

        // Create invoice lines and find-or-create products
        foreach ($parsed->lines as $index => $parsedLine) {
            $line = new InvoiceLine();
            $line->setPosition($index);
            $line->setDescription($parsedLine->description);
            $line->setQuantity($parsedLine->quantity);
            $line->setUnitOfMeasure(UblXmlGenerator::reverseMapUnitOfMeasure($parsedLine->unitOfMeasure));
            $line->setUnitPrice($parsedLine->unitPrice);
            $line->setVatRate($parsedLine->vatRate);
            $line->setVatCategoryCode($parsedLine->vatCategoryCode);
            $line->setVatAmount($parsedLine->vatAmount);
            $line->setLineTotal($parsedLine->lineTotal);
            if ($parsedLine->ublExtensions) {
                $line->setUblExtensions($parsedLine->ublExtensions);
            }

            // Find-or-create product
            if ($parsedLine->description) {
                $product = $this->findOrCreateProduct($company, $parsedLine, $result);
                $line->setProduct($product);
            }

            $invoice->addLine($line);
        }

        // Create invoice attachments from parsed XML
        foreach ($parsed->attachments as $parsedAttachment) {
            $attachment = new InvoiceAttachment();
            $attachment->setFilename($parsedAttachment->filename ?? 'attachment');
            $attachment->setMimeType($parsedAttachment->mimeType ?? 'application/octet-stream');
            if ($parsedAttachment->description) {
                $attachment->setDescription($parsedAttachment->description);
            }
            if ($parsedAttachment->content) {
                $decoded = base64_decode($parsedAttachment->content, true);
                if ($decoded !== false) {
                    $attachment->setContent($decoded);
                    $attachment->setSize(strlen($decoded));
                }
            }
            $invoice->addAttachment($attachment);
        }

        // Create sync event
        $event = new DocumentEvent();
        $event->setNewStatus(DocumentStatus::SYNCED);
        $event->setMetadata([
            'action' => 'synced_from_anaf',
            'anafMessageId' => $messageId,
            'direction' => $direction->value,
        ]);
        $invoice->addEvent($event);

        $this->entityManager->persist($invoice);

        return $invoice;
    }

    /**
     * Check if an outgoing invoice was created locally and already submitted to ANAF.
     * Match by anafUploadId (id_incarcare from ANAF message).
     */
    private function findExistingLocalInvoice(array $message, Company $company, InvoiceDirection $direction, ParsedInvoice $parsed): ?Invoice
    {
        // Only match outgoing invoices (locally created and submitted)
        if ($direction !== InvoiceDirection::OUTGOING) {
            return null;
        }

        // Try matching by upload ID from message details
        $payload = $message['detalii'] ?? '';
        if (preg_match('/id_incarcare=(\d+)/', $payload, $m)) {
            $uploadId = $m[1];
            $existing = $this->invoiceRepository->findOneBy([
                'company' => $company,
                'anafUploadId' => $uploadId,
            ]);
            if ($existing) {
                return $existing;
            }
        }

        // Fallback: match by invoice number + company for outgoing invoices without upload ID
        if ($parsed->number) {
            $existing = $this->invoiceRepository->findOneBy([
                'company' => $company,
                'number' => $parsed->number,
                'direction' => InvoiceDirection::OUTGOING,
            ]);
            if ($existing && $existing->getAnafMessageId() === null) {
                return $existing;
            }
        }

        return null;
    }

    /**
     * Merge ANAF sync data into an existing locally-created invoice.
     */
    private function mergeAnafDataIntoExisting(Invoice $invoice, string $messageId, ?string $signatureContent, array $message): void
    {
        $invoice->setAnafMessageId($messageId);
        $invoice->setAnafDownloadId($messageId);
        $invoice->setSyncedAt(new \DateTimeImmutable());

        // FACTURA TRIMISA appearing in the ANAF message list means the invoice
        // has been validated by ANAF. Transition accordingly:
        // - ISSUED → VALIDATED (manually uploaded to SPV outside the app)
        // - SENT_TO_PROVIDER → VALIDATED (submitted via Storno but status check hadn't run yet,
        //   or manually uploaded to SPV after marking as sent)
        $validatableStatuses = [DocumentStatus::ISSUED, DocumentStatus::SENT_TO_PROVIDER];
        if (in_array($invoice->getStatus(), $validatableStatuses, true)) {
            // Check if already transitioned to prevent duplicates
            $alreadyProcessed = false;
            foreach ($invoice->getEvents() as $existingEvent) {
                $action = $existingEvent->getMetadata()['action'] ?? '';
                if (in_array($action, ['synced_from_anaf', 'anaf_validated'], true)) {
                    $alreadyProcessed = true;
                    break;
                }
            }

            if (!$alreadyProcessed) {
                $previousStatus = $invoice->getStatus();
                $invoice->setStatus(DocumentStatus::VALIDATED);
                $invoice->setAnafStatus('validated');

                $event = new DocumentEvent();
                $event->setPreviousStatus($previousStatus);
                $event->setNewStatus(DocumentStatus::VALIDATED);
                $event->setMetadata([
                    'action' => 'anaf_validated',
                    'anafMessageId' => $messageId,
                    'matchedExisting' => true,
                    'source' => 'sync',
                ]);
                $invoice->addEvent($event);
            }
        }

        $this->entityManager->flush();
    }

    private function detectLateSubmission(Invoice $invoice, array $message): void
    {
        $issueDate = $invoice->getIssueDate();
        if (!$issueDate) {
            return;
        }

        // Try to extract upload date from message 'data_creare' field
        $uploadDateStr = $message['data_creare'] ?? null;
        if (!$uploadDateStr) {
            return;
        }

        try {
            // ANAF format: "202401151230" (YYYYMMDDHHmm) or ISO date
            if (preg_match('/^\d{12,14}$/', $uploadDateStr)) {
                $uploadDate = \DateTimeImmutable::createFromFormat('YmdHi', substr($uploadDateStr, 0, 12));
            } else {
                $uploadDate = new \DateTimeImmutable($uploadDateStr);
            }

            if ($uploadDate) {
                $diff = $issueDate->diff($uploadDate);
                if ($diff->days > 5 && !$diff->invert) {
                    $invoice->setIsLateSubmission(true);
                }
            }
        } catch (\Throwable) {
            // Ignore parse errors
        }
    }

    /**
     * Build a buyerSnapshot array from a parsed UBL party.
     * Mirrors {@see \App\Command\BackfillBuyerSnapshotCommand}.
     *
     * @return array<string, mixed>
     */
    private function buildBuyerSnapshotFromParsed(ParsedParty $buyer): array
    {
        $type = 'company';
        $cui = $buyer->cif;
        $cnp = null;

        if ($cui && preg_match('/^\d{13}$/', $cui)) {
            $type = 'individual';
            $cnp = $cui;
            $cui = null;
        } elseif ($cui === '0000000000000') {
            $type = 'individual';
            $cui = null;
        }

        return [
            'type' => $type,
            'name' => $buyer->name,
            'cui' => $cui,
            'cnp' => $cnp,
            'vatCode' => $buyer->vatCode,
            'isVatPayer' => $buyer->isVatPayer(),
            'registrationNumber' => $buyer->registrationNumber,
            'address' => $buyer->address,
            'city' => $buyer->city,
            'county' => $buyer->county,
            'country' => $buyer->country,
            'postalCode' => $buyer->postalCode,
            'email' => $buyer->email,
            'phone' => $buyer->phone,
            'bankName' => $buyer->bankName,
            'bankAccount' => $buyer->bankAccount,
            'clientCode' => null,
            'einvoiceIdentifiers' => null,
        ];
    }

    private function findOrCreateSupplier(Company $company, ParsedParty $party, SyncResult $result): Supplier
    {
        // Check in-memory cache first (handles unflushed entities within the same batch)
        $cacheKey = $company->getId()->toRfc4122() . ':' . $party->cif;
        if (isset($this->pendingSuppliers[$cacheKey])) {
            $cached = $this->pendingSuppliers[$cacheKey];
            $cached->setLastSyncedAt(new \DateTimeImmutable());
            return $cached;
        }

        $existing = $this->supplierRepository->findByCif($company, $party->cif);

        if ($existing) {
            $existing->setLastSyncedAt(new \DateTimeImmutable());
            if ($party->name) {
                $existing->setName($party->name);
            }
            if ($party->address) {
                $existing->setAddress($party->address);
            }
            if ($party->city || $party->county) {
                $addr = AddressNormalizer::normalizeBucharest(
                    $party->county ?? $existing->getCounty() ?? '',
                    $party->city ?? $existing->getCity() ?? '',
                );
                $existing->setCity($addr['city']);
                $existing->setCounty($addr['county']);
            }
            if ($party->vatCode) {
                $existing->setVatCode($party->vatCode);
                $existing->setIsVatPayer(true);
            }
            if ($party->registrationNumber) {
                $existing->setRegistrationNumber($party->registrationNumber);
            }
            if ($party->phone) {
                $existing->setPhone($party->phone);
            }
            if ($party->email) {
                $existing->setEmail($party->email);
            }
            if ($party->bankAccount) {
                $existing->setBankAccount($party->bankAccount);
            }
            if ($party->bankName) {
                $existing->setBankName($party->bankName);
            }

            $this->pendingSuppliers[$cacheKey] = $existing;
            return $existing;
        }

        $supplier = new Supplier();
        $supplier->setCompany($company);
        $supplier->setCif($party->cif);
        $supplier->setName($party->name ?? 'Unknown');
        $supplier->setSource('anaf_sync');
        $supplier->setLastSyncedAt(new \DateTimeImmutable());
        $supplier->setCountry($party->country);

        if ($party->vatCode) {
            $supplier->setVatCode($party->vatCode);
            $supplier->setIsVatPayer(true);
        }
        if ($party->registrationNumber) {
            $supplier->setRegistrationNumber($party->registrationNumber);
        }
        if ($party->address) {
            $supplier->setAddress($party->address);
        }
        if ($party->city || $party->county) {
            $addr = AddressNormalizer::normalizeBucharest($party->county ?? '', $party->city ?? '');
            $supplier->setCity($addr['city']);
            $supplier->setCounty($addr['county']);
        }
        if ($party->postalCode) {
            $supplier->setPostalCode($party->postalCode);
        }
        if ($party->phone) {
            $supplier->setPhone($party->phone);
        }
        if ($party->email) {
            $supplier->setEmail($party->email);
        }
        if ($party->bankAccount) {
            $supplier->setBankAccount($party->bankAccount);
        }
        if ($party->bankName) {
            $supplier->setBankName($party->bankName);
        }

        $this->entityManager->persist($supplier);
        $this->pendingSuppliers[$cacheKey] = $supplier;

        return $supplier;
    }

    private function findOrCreateBankAccount(Company $company, string $iban, ?string $bankName, string $currency): void
    {
        $existing = $this->bankAccountRepository->findByIban($company, $iban);
        if ($existing) {
            return;
        }

        $bankAccount = new BankAccount();
        $bankAccount->setCompany($company);
        $bankAccount->setIban($iban);
        $bankAccount->setBankName($bankName);
        $bankAccount->setCurrency($currency);
        $bankAccount->setSource('anaf_sync');

        $this->entityManager->persist($bankAccount);
    }

    private function findOrCreateClient(Company $company, ParsedParty $party, SyncResult $result): Client
    {
        $isPlaceholder = $this->isPlaceholderCif($party->cif);
        $isCnp = !$isPlaceholder && $this->isRomanianCnp($party->cif);
        $isIndividual = $isPlaceholder || $isCnp;
        $companyId = $company->getId()->toRfc4122();

        // Extract clean CNP value (strip RO prefix)
        $cnpValue = $isCnp ? preg_replace('/^RO/i', '', $party->cif) : null;

        // Build cache key: CNP individuals by CNP, placeholder individuals by name, companies by CIF
        if ($isCnp) {
            $cacheKey = $companyId . ':cnp:' . $cnpValue;
        } elseif ($isPlaceholder) {
            $cacheKey = $companyId . ':individual:' . mb_strtolower(trim($party->name ?? ''));
        } else {
            $cacheKey = $companyId . ':' . $party->cif;
        }

        if (isset($this->pendingClients[$cacheKey])) {
            $cached = $this->pendingClients[$cacheKey];
            $cached->setLastSyncedAt(new \DateTimeImmutable());
            return $cached;
        }

        // Lookup: CNP individuals by CNP, placeholder individuals by name, companies by CUI
        if ($isCnp) {
            $existing = $this->clientRepository->findByCnp($company, $cnpValue);
        } elseif ($isPlaceholder) {
            $existing = $party->name ? $this->clientRepository->findIndividualByName($company, $party->name) : null;
        } else {
            $existing = $this->clientRepository->findByCui($company, $party->cif);
            if (!$existing) {
                $existing = $this->clientRepository->findByCuiIncludingDeleted($company, $party->cif);
                if ($existing && $existing->isDeleted()) {
                    $existing->restore();
                    $result->incrementNewClients();
                }
            }
        }

        if ($existing) {
            $this->updateClientFromParty($existing, $party);
            $this->pendingClients[$cacheKey] = $existing;
            return $existing;
        }

        $client = new Client();
        $client->setCompany($company);
        $client->setName($party->name ?? 'Unknown');
        $client->setSource('anaf_sync');
        $client->setLastSyncedAt(new \DateTimeImmutable());
        $client->setCountry($party->country);

        if ($isCnp) {
            $client->setType('individual');
            $client->setCnp($cnpValue);
            // Don't store CNP as CUI
        } elseif ($isPlaceholder) {
            $client->setType('individual');
            // Don't store the placeholder CIF
        } else {
            $client->setCui($party->cif);
        }

        if ($party->vatCode && !$isIndividual) {
            $client->setVatCode($party->vatCode);
            $client->setIsVatPayer(true);
        }
        if ($party->registrationNumber) {
            $client->setRegistrationNumber($party->registrationNumber);
        }
        if ($party->address) {
            $client->setAddress($party->address);
        }
        if ($party->city || $party->county) {
            $addr = AddressNormalizer::normalizeBucharest($party->county ?? '', $party->city ?? '');
            $client->setCity($addr['city']);
            $client->setCounty($addr['county']);
        }
        if ($party->postalCode) {
            $client->setPostalCode($party->postalCode);
        }
        if ($party->phone) {
            $client->setPhone($party->phone);
        }
        if ($party->email) {
            $client->setEmail($party->email);
        }
        if ($party->bankAccount) {
            $client->setBankAccount($party->bankAccount);
        }
        if ($party->bankName) {
            $client->setBankName($party->bankName);
        }

        $this->entityManager->persist($client);
        $this->pendingClients[$cacheKey] = $client;
        $result->incrementNewClients();

        return $client;
    }

    private function updateClientFromParty(Client $client, ParsedParty $party): void
    {
        $client->setLastSyncedAt(new \DateTimeImmutable());
        if ($party->name) {
            $client->setName($party->name);
        }
        if ($party->address) {
            $client->setAddress($party->address);
        }
        if ($party->city || $party->county) {
            $addr = AddressNormalizer::normalizeBucharest(
                $party->county ?? $client->getCounty() ?? '',
                $party->city ?? $client->getCity() ?? '',
            );
            $client->setCity($addr['city']);
            $client->setCounty($addr['county']);
        }
        if ($party->vatCode) {
            $client->setVatCode($party->vatCode);
            $client->setIsVatPayer(true);
        }
        if ($party->registrationNumber) {
            $client->setRegistrationNumber($party->registrationNumber);
        }
        if ($party->phone) {
            $client->setPhone($party->phone);
        }
        if ($party->email) {
            $client->setEmail($party->email);
        }
        if ($party->bankAccount) {
            $client->setBankAccount($party->bankAccount);
        }
        if ($party->bankName) {
            $client->setBankName($party->bankName);
        }
    }

    /**
     * Detect placeholder CIFs used for physical persons in e-Factura (e.g. "0000000000000").
     */
    private function isPlaceholderCif(?string $cif): bool
    {
        if ($cif === null || $cif === '') {
            return false;
        }

        // Strip any remaining RO prefix
        $clean = preg_replace('/^RO/i', '', $cif);

        // All zeros = placeholder for physical persons
        return (bool) preg_match('/^0+$/', $clean);
    }

    /**
     * Detect Romanian CNP (Cod Numeric Personal) — 13 digits, starts with 1-9.
     * CUIs are typically 2-10 digits, so 13 digits is always a CNP.
     */
    private function isRomanianCnp(?string $cif): bool
    {
        if ($cif === null || $cif === '') {
            return false;
        }

        $clean = preg_replace('/^RO/i', '', $cif);

        return (bool) preg_match('/^[1-9]\d{12}$/', $clean);
    }

    private function findOrCreateProduct(Company $company, ParsedInvoiceLine $line, SyncResult $result): Product
    {
        // Lookup by name + unit + company
        $existing = $this->productRepository->findOneBy([
            'company' => $company,
            'name' => mb_substr($line->description, 0, 255),
            'unitOfMeasure' => $line->unitOfMeasure,
        ]);

        if ($existing) {
            $existing->setLastSyncedAt(new \DateTimeImmutable());
            return $existing;
        }

        $product = new Product();
        $product->setCompany($company);
        $product->setName(mb_substr($line->description, 0, 255));
        $product->setUnitOfMeasure(UblXmlGenerator::reverseMapUnitOfMeasure($line->unitOfMeasure));
        $product->setDefaultPrice($line->unitPrice);
        $product->setVatRate($line->vatRate);
        $product->setVatCategoryCode($line->vatCategoryCode);
        $product->setSource('anaf_sync');
        $product->setLastSyncedAt(new \DateTimeImmutable());

        $this->entityManager->persist($product);
        $result->incrementNewProducts();

        return $product;
    }

    private function calculateDaysBack(Company $company): int
    {
        if ($company->getLastSyncedAt()) {
            $diff = (new \DateTimeImmutable())->diff($company->getLastSyncedAt());
            return max($diff->days + 1, 10); // Always sync at least last 10 days to catch any late submissions
        }

        return $company->getSyncDaysBack();
    }

    /**
     * @param list<array{id: string, number: ?string, direction: ?string, total: string, currency: string, senderName: ?string, receiverName: ?string}> $summaries
     */
    private function notifyNewDocuments(Company $company, int $count, array $summaries = []): void
    {
        try {
            $users = $this->membershipRepository->findActiveUsersByCompany($company);
            $companyName = $company->getName() ?? '—';

            // Build a title that always identifies the company. Multi-company users
            // glance at notifications and need to know "for which company" first.
            $title = sprintf('%s — e-Factura', $companyName);

            // Single document: name the counterparty + amount + currency. This is
            // the high-value case for B2B users — they want to know what arrived
            // at a glance without opening the app.
            if ($count === 1 && !empty($summaries[0])) {
                $s = $summaries[0];
                $direction = $s['direction'] === 'incoming' ? 'incoming' : 'outgoing';
                $counterparty = $direction === 'incoming'
                    ? ($s['senderName'] ?: '—')
                    : ($s['receiverName'] ?: '—');
                $amount = $this->formatAmount($s['total'], $s['currency']);
                $number = $s['number'] ?: '#?';
                $message = $direction === 'incoming'
                    ? sprintf('Incoming %s from %s · %s', $number, $counterparty, $amount)
                    : sprintf('Outgoing %s to %s · %s', $number, $counterparty, $amount);

                $messageKey = $direction === 'incoming'
                    ? 'notification.efactura.new_incoming.message'
                    : 'notification.efactura.new_outgoing.message';
                $messageParams = [
                    'company' => $companyName,
                    'number' => $number,
                    'counterparty' => $counterparty,
                    'amount' => $amount,
                ];
            } else {
                // Multiple documents: surface the total per direction so the user
                // knows the overall shape before tapping in.
                $byDir = ['incoming' => 0, 'outgoing' => 0];
                foreach ($summaries as $s) {
                    $key = $s['direction'] === 'outgoing' ? 'outgoing' : 'incoming';
                    $byDir[$key]++;
                }
                $parts = [];
                if ($byDir['incoming'] > 0) {
                    $parts[] = $byDir['incoming'] . ' incoming';
                }
                if ($byDir['outgoing'] > 0) {
                    $parts[] = $byDir['outgoing'] . ' outgoing';
                }
                $breakdown = $parts ? ' (' . implode(', ', $parts) . ')' : '';
                $message = sprintf('%d new documents received%s', $count, $breakdown);

                $messageKey = 'notification.efactura.new_documents_multi.message';
                $messageParams = [
                    'company' => $companyName,
                    'count' => $count,
                    'incoming' => $byDir['incoming'],
                    'outgoing' => $byDir['outgoing'],
                ];
            }

            foreach ($users as $user) {
                $this->notificationService->createNotification(
                    $user,
                    'efactura.new_documents',
                    $title,
                    $message,
                    [
                        'companyId' => $company->getId()->toRfc4122(),
                        'companyName' => $companyName,
                        'count' => $count,
                        'titleKey' => 'notification.efactura.new_documents.title',
                        'titleParams' => ['company' => $companyName],
                        'messageKey' => $messageKey,
                        'messageParams' => $messageParams,
                        'invoices' => $summaries,
                    ],
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send new documents notification', [
                'company' => $company->getName(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function formatAmount(string $total, string $currency): string
    {
        return number_format((float) $total, 2, '.', ',') . ' ' . $currency;
    }

    private function processErrorMessage(?EFacturaMessage $spvMessage, string $messageId, Company $company, string $token, SyncResult $result): void
    {
        if (!$spvMessage) {
            return;
        }

        // Skip if error was already downloaded and parsed
        if ($spvMessage->getErrorMessage() !== null) {
            return;
        }

        try {
            $zipData = $this->eFacturaClient->download($messageId, $token);
            $errorText = $this->parseErrorZip($zipData);

            if ($errorText) {
                $spvMessage->setErrorMessage($errorText);
            } else {
                $spvMessage->setErrorMessage('ANAF error (details unavailable)');
            }
            $spvMessage->setStatus('error');

            // Link to original invoice via uploadId
            if ($spvMessage->getUploadId()) {
                $invoice = $this->invoiceRepository->findOneBy([
                    'company' => $company,
                    'anafUploadId' => $spvMessage->getUploadId(),
                ]);
                if ($invoice) {
                    $spvMessage->setInvoice($invoice);
                    // Only reject if still awaiting validation — don't downgrade already validated/rejected invoices
                    if ($invoice->getStatus() === DocumentStatus::SENT_TO_PROVIDER) {
                        $invoice->setStatus(DocumentStatus::REJECTED);
                        $invoice->setAnafErrorMessage($errorText);
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to process error message from ANAF', [
                'messageId' => $messageId,
                'error' => $e->getMessage(),
            ]);
            $spvMessage->setStatus('error');
            $spvMessage->setErrorMessage('Error downloading details: ' . $e->getMessage());
        }
    }

    private function parseErrorZip(string $zipData): ?string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'anaf-error');
        file_put_contents($tempFile, $zipData);

        $zip = new \ZipArchive();
        if ($zip->open($tempFile) !== true) {
            unlink($tempFile);
            return null;
        }

        $errors = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $content = $zip->getFromIndex($i);
            if ($content === false) {
                continue;
            }

            $xml = @simplexml_load_string($content);
            if ($xml === false) {
                // Not XML — might be plain text error
                $trimmed = trim($content);
                if ($trimmed !== '') {
                    $errors[] = $trimmed;
                }
                continue;
            }

            $this->extractErrorsFromXml($xml, $errors);
        }

        $zip->close();
        unlink($tempFile);

        return empty($errors) ? null : implode("\n", array_unique($errors));
    }

    private function extractErrorsFromXml(\SimpleXMLElement $xml, array &$errors): void
    {
        // Check errorMessage attribute (ANAF uses <error errorMessage="..."/>)
        foreach ($xml->attributes() as $name => $value) {
            if (strtolower((string) $name) === 'errormessage' && (string) $value !== '') {
                $errors[] = (string) $value;
            }
        }

        // Check text content of error-like elements
        $nodeName = strtolower($xml->getName());
        if (str_contains($nodeName, 'error') && trim((string) $xml) !== '' && count($xml->children()) === 0) {
            $errors[] = trim((string) $xml);
        }

        // Recurse into direct children
        foreach ($xml->children() as $child) {
            $this->extractErrorsFromXml($child, $errors);
        }

        // Also check namespaced children
        foreach ($xml->getNamespaces(true) as $ns) {
            foreach ($xml->children($ns) as $child) {
                $this->extractErrorsFromXml($child, $errors);
            }
        }
    }

    /**
     * Reset the EntityManager after a DBAL/ORM exception (which closes it).
     */
    private function resetEntityManager(): void
    {
        if (!$this->entityManager->isOpen()) {
            /** @var EntityManagerInterface $em */
            $em = $this->managerRegistry->resetManager();
            $this->entityManager = $em;
        }
        $this->documentSeriesManager->clearCache();
        $this->pendingClients = [];
        $this->pendingSuppliers = [];
    }

    /**
     * Detect ANAF "no SPV access for this CIF" error. Matches the known
     * Romanian phrasing regardless of exact casing or CIF suffix.
     */
    private function isSpvAccessDenied(string $error): bool
    {
        $lower = mb_strtolower($error);
        return str_contains($lower, 'aveti drept in spv')
            || str_contains($lower, 'spv pentru cif');
    }

    private function sanitizeErrorForUser(string $error): ?string
    {
        // Internal persistence errors — don't expose ORM details
        if (str_contains($error, 'cascade persist') || str_contains($error, 'EntityManager')) {
            return 'Internal error saving data. Sync will be retried automatically.';
        }

        // Database errors — transient, don't expose SQL
        if (str_contains($error, 'SQLSTATE') || str_contains($error, 'deadlock')) {
            return 'Temporary database error. Sync will be retried automatically.';
        }

        // Batch/flush errors — internal processing
        if (preg_match('/^(Batch flush|Final flush|Default series) error: /', $error)) {
            return 'Internal error processing data. Sync will be retried automatically.';
        }

        // Rate limits — transient, will resolve on next sync
        if (str_contains($error, 'rate limit') || str_contains($error, 'Rate limit')) {
            return null;
        }

        // Network timeouts — don't expose URLs
        if (str_contains($error, 'Idle timeout') || str_contains($error, 'Connection timed out')) {
            return 'ANAF servers are not responding. Sync will be retried automatically.';
        }

        // cURL / connection errors — don't expose technical details
        if (preg_match('/cURL error|Connection refused|Could not resolve host|SSL/', $error)) {
            return 'Connection error to ANAF servers. Sync will be retried automatically.';
        }

        // HTTP errors with URLs — strip the URL, keep the context
        if (str_contains($error, 'Failed to list messages from ANAF')) {
            return 'ANAF servers are currently unavailable. Sync will be retried automatically.';
        }

        // Generic "Error processing message" — strip message ID and technical details
        if (preg_match('/^Error processing message \d+: (.+)$/', $error, $m)) {
            $inner = $m[1];
            if (preg_match('/https?:\/\/|cURL|timeout|Idle/i', $inner)) {
                return 'Error processing an ANAF message. Sync will be retried automatically.';
            }
            return 'Error processing an ANAF message: ' . $this->stripUrls($inner);
        }

        // Strip any remaining URLs from error messages
        return $this->stripUrls($error);
    }

    /**
     * Remove URLs from error messages to avoid exposing internal endpoints.
     */
    private function stripUrls(string $text): string
    {
        return trim(preg_replace('/https?:\/\/[^\s"<>\)]+/', '', $text));
    }

    private function notifySyncErrors(Company $company, array $errors): void
    {
        try {
            $userErrors = array_values(array_unique(array_filter(array_map([$this, 'sanitizeErrorForUser'], $errors))));

            if (empty($userErrors)) {
                return;
            }

            $channel = self::CHANNEL_PREFIX . $company->getId()->toRfc4122();
            $this->centrifugo->publish($channel, [
                'type' => 'sync.error',
                'errors' => $userErrors,
                'companyId' => $company->getId()->toRfc4122(),
            ]);
            $this->webhookDispatcher->dispatchForCompany($company, 'sync.error', [
                'errors' => $userErrors,
                'companyId' => $company->getId()->toRfc4122(),
            ]);

            $users = $this->membershipRepository->findActiveUsersByCompany($company);
            $errorCount = count($userErrors);
            $firstError = $userErrors[0] ?? 'Unknown error';
            $message = $errorCount === 1
                ? sprintf('%s — %s', $company->getName(), $firstError)
                : sprintf('%s — %d sync errors. First: %s', $company->getName(), $errorCount, $firstError);

            $messageKey = $errorCount === 1 ? 'notification.sync.error.single' : 'notification.sync.error.multiple';
            $messageParams = [
                'company' => $company->getName(),
                'error' => $firstError,
                'count' => $errorCount,
                'first_error' => $firstError,
            ];

            foreach ($users as $user) {
                $this->notificationService->createNotification(
                    $user,
                    'sync.error',
                    'e-Factura sync error',
                    $message,
                    [
                        'companyId' => $company->getId()->toRfc4122(),
                        'errors' => array_slice($userErrors, 0, 5),
                        'messageKey' => $messageKey,
                        'messageParams' => $messageParams,
                    ],
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send sync error notification', [
                'company' => $company->getName(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
