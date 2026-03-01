<?php

namespace App\Manager;

use App\Entity\Company;
use App\Entity\DocumentEvent;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\User;
use App\Enum\DocumentStatus;
use App\Enum\DocumentType;
use App\Enum\EInvoiceProvider;
use App\Enum\InvoiceDirection;
use App\Enum\InvoiceTypeCode;
use App\Manager\Trait\DocumentCalculationTrait;
use App\Message\EInvoice\SubmitEInvoiceMessage;
use App\Repository\ClientRepository;
use App\Repository\DocumentSeriesRepository;
use App\Repository\InvoiceRepository;
use App\Repository\StripeConnectAccountRepository;
use App\Service\Anaf\AnafTokenResolver;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

class InvoiceManager
{
    use DocumentCalculationTrait;
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentSeriesRepository $documentSeriesRepository,
        private readonly ClientRepository $clientRepository,
        private readonly StripeConnectAccountRepository $connectAccountRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly AnafTokenResolver $anafTokenResolver,
    ) {}

    public function find(string $uuid): ?Invoice
    {
        return $this->invoiceRepository->find(Uuid::fromString($uuid));
    }

    public function listByCompany(Company $company, array $filters = [], int $page = 1, int $limit = 20): array
    {
        return $this->invoiceRepository->findByCompanyPaginated($company, $filters, $page, $limit);
    }

    public function create(Company $company, array $data, User $user): Invoice
    {
        $invoice = new Invoice();
        $invoice->setCompany($company);
        $invoice->setStatus(DocumentStatus::DRAFT);
        $invoice->setDirection(InvoiceDirection::OUTGOING);

        // Document type
        $docType = isset($data['documentType'])
            ? DocumentType::from($data['documentType'])
            : DocumentType::INVOICE;
        $invoice->setDocumentType($docType);

        // Resolve client
        if (!empty($data['clientId'])) {
            $client = $this->clientRepository->find(Uuid::fromString($data['clientId']));
            if ($client) {
                $invoice->setClient($client);
                $invoice->setReceiverName($client->getName());
                $invoice->setReceiverCif($client->getCui() ?? $client->getCnp());
            }
        }
        // Allow setting receiver info directly (without a Client entity)
        if (!empty($data['receiverName'])) {
            $invoice->setReceiverName($data['receiverName']);
        }
        if (!empty($data['receiverCif'])) {
            $invoice->setReceiverCif($data['receiverCif']);
        }

        // Sender info from company
        $invoice->setSenderName($company->getName());
        $invoice->setSenderCif((string) $company->getCif());

        // Issue date is always today — cannot be in the past or future
        $invoice->setIssueDate(new \DateTime('today'));
        if (isset($data['dueDate'])) {
            $invoice->setDueDate(new \DateTime($data['dueDate']));
        }
        $invoice->setCurrency($data['currency'] ?? $company->getDefaultCurrency());
        if (isset($data['invoiceTypeCode'])) {
            $typeCode = InvoiceTypeCode::tryFrom($data['invoiceTypeCode']);
            if ($typeCode === null) {
                throw new \InvalidArgumentException(sprintf('Invalid invoiceTypeCode: %s', $data['invoiceTypeCode']));
            }
            $invoice->setInvoiceTypeCode($typeCode->value);
        }
        $invoice->setNotes($data['notes'] ?? null);
        $invoice->setPaymentTerms($data['paymentTerms'] ?? null);
        $invoice->setDeliveryLocation($data['deliveryLocation'] ?? null);
        $invoice->setProjectReference($data['projectReference'] ?? null);

        // New fields
        $invoice->setOrderNumber($data['orderNumber'] ?? null);
        $invoice->setContractNumber($data['contractNumber'] ?? null);
        $invoice->setIssuerName($data['issuerName'] ?? null);
        $invoice->setIssuerId($data['issuerId'] ?? null);
        $invoice->setMentions($data['mentions'] ?? null);
        $invoice->setInternalNote($data['internalNote'] ?? null);
        $invoice->setSalesAgent($data['salesAgent'] ?? null);
        $invoice->setDeputyName($data['deputyName'] ?? null);
        $invoice->setDeputyIdentityCard($data['deputyIdentityCard'] ?? null);
        $invoice->setDeputyAuto($data['deputyAuto'] ?? null);
        $invoice->setIdempotencyKey($data['idempotencyKey'] ?? null);

        // Options
        $invoice->setTvaLaIncasare($data['tvaLaIncasare'] ?? false);
        $invoice->setPlatitorTva($data['platitorTva'] ?? false);

        // Default plataOnline from Stripe Connect paymentEnabledByDefault
        $plataOnlineDefault = false;
        if (!array_key_exists('plataOnline', $data)) {
            $connectAccount = $this->connectAccountRepository->findByCompany($company);
            if ($connectAccount && $connectAccount->isChargesEnabled() && $connectAccount->isPaymentEnabledByDefault()) {
                $plataOnlineDefault = true;
            }
        }
        $invoice->setPlataOnline($data['plataOnline'] ?? $plataOnlineDefault);

        // Client balance
        $invoice->setShowClientBalance($data['showClientBalance'] ?? false);
        $invoice->setClientBalanceExisting($data['clientBalanceExisting'] ?? null);
        $invoice->setClientBalanceOverdue($data['clientBalanceOverdue'] ?? null);

        // e-Factura BT fields
        if (isset($data['taxPointDate'])) {
            $invoice->setTaxPointDate(new \DateTime($data['taxPointDate']));
        }
        $invoice->setTaxPointDateCode($data['taxPointDateCode'] ?? null);
        $invoice->setBuyerReference($data['buyerReference'] ?? null);
        $invoice->setReceivingAdviceReference($data['receivingAdviceReference'] ?? null);
        $invoice->setDespatchAdviceReference($data['despatchAdviceReference'] ?? null);
        $invoice->setTenderOrLotReference($data['tenderOrLotReference'] ?? null);
        $invoice->setInvoicedObjectIdentifier($data['invoicedObjectIdentifier'] ?? null);
        $invoice->setBuyerAccountingReference($data['buyerAccountingReference'] ?? null);
        $invoice->setBusinessProcessType($data['businessProcessType'] ?? null);
        $invoice->setPayeeName($data['payeeName'] ?? null);
        $invoice->setPayeeIdentifier($data['payeeIdentifier'] ?? null);
        $invoice->setPayeeLegalRegistrationIdentifier($data['payeeLegalRegistrationIdentifier'] ?? null);

        if (isset($data['language'])) {
            $invoice->setLanguage($data['language']);
        }

        if (isset($data['exchangeRate'])) {
            $invoice->setExchangeRate($data['exchangeRate']);
        }

        // Link to parent document (for refund)
        if (!empty($data['parentDocumentId'])) {
            $parentInvoice = $this->invoiceRepository->find(Uuid::fromString($data['parentDocumentId']));
            if ($parentInvoice && $parentInvoice->getCompany()?->getId()->equals($company->getId())) {
                $invoice->setParentDocument($parentInvoice);
            }
        }

        // Link to DocumentSeries (number assigned at submit/finalize, not at creation)
        if (!empty($data['documentSeriesId'])) {
            $series = $this->documentSeriesRepository->find(Uuid::fromString($data['documentSeriesId']));
            if ($series && $series->getCompany()?->getId()->equals($company->getId())) {
                $invoice->setDocumentSeries($series);
            }
        }

        // Draft invoices get a temporary number
        $invoice->setNumber('DRAFT-' . substr(Uuid::v7()->toRfc4122(), 0, 8));

        // Create lines
        $this->setLines($invoice, $data['lines'] ?? []);

        // Recalculate totals
        $this->recalculateTotals($invoice);

        // Add document event
        $event = new DocumentEvent();
        $event->setNewStatus(DocumentStatus::DRAFT);
        $event->setCreatedBy($user);
        $event->setMetadata(['action' => 'created']);
        $invoice->addEvent($event);

        $this->entityManager->persist($invoice);
        $this->entityManager->flush();

        return $invoice;
    }

    public function update(Invoice $invoice, array $data, User $user): Invoice
    {
        if (!$invoice->isEditable()) {
            throw new \DomainException('Invoice is not editable.');
        }

        // Update scalar fields
        if (isset($data['issueDate'])) {
            $invoice->setIssueDate(new \DateTime($data['issueDate']));
        }
        if (isset($data['dueDate'])) {
            $invoice->setDueDate(new \DateTime($data['dueDate']));
        }
        if (isset($data['currency'])) {
            $invoice->setCurrency($data['currency']);
        }
        if (array_key_exists('notes', $data)) {
            $invoice->setNotes($data['notes']);
        }
        if (array_key_exists('paymentTerms', $data)) {
            $invoice->setPaymentTerms($data['paymentTerms']);
        }
        if (array_key_exists('deliveryLocation', $data)) {
            $invoice->setDeliveryLocation($data['deliveryLocation']);
        }
        if (array_key_exists('projectReference', $data)) {
            $invoice->setProjectReference($data['projectReference']);
        }
        if (isset($data['documentType'])) {
            $invoice->setDocumentType(DocumentType::from($data['documentType']));
        }
        if (array_key_exists('invoiceTypeCode', $data)) {
            if ($data['invoiceTypeCode'] !== null) {
                $typeCode = InvoiceTypeCode::tryFrom($data['invoiceTypeCode']);
                if ($typeCode === null) {
                    throw new \InvalidArgumentException(sprintf('Invalid invoiceTypeCode: %s', $data['invoiceTypeCode']));
                }
                $invoice->setInvoiceTypeCode($typeCode->value);
            } else {
                $invoice->setInvoiceTypeCode(null);
            }
        }

        // Update new fields
        if (array_key_exists('orderNumber', $data)) {
            $invoice->setOrderNumber($data['orderNumber']);
        }
        if (array_key_exists('contractNumber', $data)) {
            $invoice->setContractNumber($data['contractNumber']);
        }
        if (array_key_exists('issuerName', $data)) {
            $invoice->setIssuerName($data['issuerName']);
        }
        if (array_key_exists('issuerId', $data)) {
            $invoice->setIssuerId($data['issuerId']);
        }
        if (array_key_exists('mentions', $data)) {
            $invoice->setMentions($data['mentions']);
        }
        if (array_key_exists('internalNote', $data)) {
            $invoice->setInternalNote($data['internalNote']);
        }
        if (array_key_exists('salesAgent', $data)) {
            $invoice->setSalesAgent($data['salesAgent']);
        }
        if (array_key_exists('deputyName', $data)) {
            $invoice->setDeputyName($data['deputyName']);
        }
        if (array_key_exists('deputyIdentityCard', $data)) {
            $invoice->setDeputyIdentityCard($data['deputyIdentityCard']);
        }
        if (array_key_exists('deputyAuto', $data)) {
            $invoice->setDeputyAuto($data['deputyAuto']);
        }

        // Options
        if (array_key_exists('tvaLaIncasare', $data)) {
            $invoice->setTvaLaIncasare((bool) $data['tvaLaIncasare']);
        }
        if (array_key_exists('platitorTva', $data)) {
            $invoice->setPlatitorTva((bool) $data['platitorTva']);
        }
        if (array_key_exists('plataOnline', $data)) {
            $invoice->setPlataOnline((bool) $data['plataOnline']);
        }

        // Client balance
        if (array_key_exists('showClientBalance', $data)) {
            $invoice->setShowClientBalance((bool) $data['showClientBalance']);
        }
        if (array_key_exists('clientBalanceExisting', $data)) {
            $invoice->setClientBalanceExisting($data['clientBalanceExisting']);
        }
        if (array_key_exists('clientBalanceOverdue', $data)) {
            $invoice->setClientBalanceOverdue($data['clientBalanceOverdue']);
        }

        // e-Factura BT fields
        if (array_key_exists('taxPointDate', $data)) {
            $invoice->setTaxPointDate($data['taxPointDate'] ? new \DateTime($data['taxPointDate']) : null);
        }
        if (array_key_exists('taxPointDateCode', $data)) {
            $invoice->setTaxPointDateCode($data['taxPointDateCode']);
        }
        if (array_key_exists('buyerReference', $data)) {
            $invoice->setBuyerReference($data['buyerReference']);
        }
        if (array_key_exists('receivingAdviceReference', $data)) {
            $invoice->setReceivingAdviceReference($data['receivingAdviceReference']);
        }
        if (array_key_exists('despatchAdviceReference', $data)) {
            $invoice->setDespatchAdviceReference($data['despatchAdviceReference']);
        }
        if (array_key_exists('tenderOrLotReference', $data)) {
            $invoice->setTenderOrLotReference($data['tenderOrLotReference']);
        }
        if (array_key_exists('invoicedObjectIdentifier', $data)) {
            $invoice->setInvoicedObjectIdentifier($data['invoicedObjectIdentifier']);
        }
        if (array_key_exists('buyerAccountingReference', $data)) {
            $invoice->setBuyerAccountingReference($data['buyerAccountingReference']);
        }
        if (array_key_exists('businessProcessType', $data)) {
            $invoice->setBusinessProcessType($data['businessProcessType']);
        }
        if (array_key_exists('payeeName', $data)) {
            $invoice->setPayeeName($data['payeeName']);
        }
        if (array_key_exists('payeeIdentifier', $data)) {
            $invoice->setPayeeIdentifier($data['payeeIdentifier']);
        }
        if (array_key_exists('payeeLegalRegistrationIdentifier', $data)) {
            $invoice->setPayeeLegalRegistrationIdentifier($data['payeeLegalRegistrationIdentifier']);
        }

        if (isset($data['language'])) {
            $invoice->setLanguage($data['language']);
        }
        if (isset($data['exchangeRate'])) {
            $invoice->setExchangeRate($data['exchangeRate']);
        }

        // Update client
        if (isset($data['clientId'])) {
            $client = $this->clientRepository->find(Uuid::fromString($data['clientId']));
            if ($client) {
                $invoice->setClient($client);
                $invoice->setReceiverName($client->getName());
                $invoice->setReceiverCif($client->getCui() ?? $client->getCnp());
            }
        }
        // Manual receiver info (when no client entity selected)
        if (!isset($data['clientId'])) {
            if (!empty($data['receiverName'])) {
                $invoice->setReceiverName($data['receiverName']);
            }
            if (!empty($data['receiverCif'])) {
                $invoice->setReceiverCif($data['receiverCif']);
            }
        }

        // Allow series change on draft invoices
        if (isset($data['documentSeriesId']) && $invoice->getStatus() === DocumentStatus::DRAFT) {
            $series = $this->documentSeriesRepository->find(Uuid::fromString($data['documentSeriesId']));
            if ($series && $series->getCompany()?->getId()->equals($invoice->getCompany()->getId())) {
                $invoice->setDocumentSeries($series);
            }
        }

        // Replace lines (orphanRemoval handles cleanup)
        if (isset($data['lines'])) {
            $invoice->clearLines();
            $this->entityManager->flush(); // flush to trigger orphanRemoval
            $this->setLines($invoice, $data['lines']);
        }

        // Recalculate totals
        $this->recalculateTotals($invoice);

        $this->entityManager->flush();

        return $invoice;
    }

    public function delete(Invoice $invoice): void
    {
        if (!$invoice->isDeletable()) {
            throw new \DomainException('Invoice cannot be deleted.');
        }

        // Decrement series number if invoice was not sent to SPV and holds the latest number
        $series = $invoice->getDocumentSeries();
        if ($series && $invoice->getAnafUploadId() === null) {
            $prefix = $series->getPrefix();
            $number = $invoice->getNumber();
            if ($prefix && $number && str_starts_with($number, $prefix)) {
                $sequenceStr = substr($number, strlen($prefix));
                $sequenceNum = (int) $sequenceStr;
                if ($sequenceNum > 0 && $sequenceNum === $series->getCurrentNumber()) {
                    $this->entityManager->wrapInTransaction(function () use ($series, $sequenceNum) {
                        $this->entityManager->lock($series, LockMode::PESSIMISTIC_WRITE);
                        $this->entityManager->refresh($series);
                        // Re-check after lock in case of concurrent changes
                        if ($series->getCurrentNumber() === $sequenceNum) {
                            $series->setCurrentNumber($sequenceNum - 1);
                        }
                    });
                }
            }
        }

        $invoice->setDeletedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    /**
     * Issue a draft invoice: assign final number from series, change status to ISSUED.
     * If company has efacturaDelayHours set, schedules for automatic ANAF submission.
     * If null (default), no automatic submission — user must submit manually.
     */
    public function issue(Invoice $invoice, User $user): void
    {
        if ($invoice->getStatus() !== DocumentStatus::DRAFT) {
            throw new \DomainException('Only draft invoices can be issued.');
        }

        // Assign final number from DocumentSeries with pessimistic lock
        $series = $invoice->getDocumentSeries();
        if ($series) {
            $this->entityManager->wrapInTransaction(function () use ($series, $invoice) {
                $this->entityManager->lock($series, LockMode::PESSIMISTIC_WRITE);
                $this->entityManager->refresh($series);
                $newNumber = $series->getCurrentNumber() + 1;
                $series->setCurrentNumber($newNumber);
                $invoice->setNumber($series->getPrefix() . str_pad((string) $newNumber, 4, '0', STR_PAD_LEFT));
            });
        }

        $isRefund = $invoice->getParentDocument() !== null;
        $previousStatus = $invoice->getStatus();
        $newStatus = $isRefund ? DocumentStatus::REFUND : DocumentStatus::ISSUED;
        $invoice->setStatus($newStatus);

        // Mark parent invoice as refunded
        if ($isRefund) {
            $parent = $invoice->getParentDocument();
            $parentPrevStatus = $parent->getStatus();
            $parent->setStatus(DocumentStatus::REFUNDED);

            $parentEvent = new DocumentEvent();
            $parentEvent->setPreviousStatus($parentPrevStatus);
            $parentEvent->setNewStatus(DocumentStatus::REFUNDED);
            $parentEvent->setCreatedBy($user);
            $parentEvent->setMetadata([
                'action' => 'refunded',
                'refundInvoiceId' => $invoice->getId()?->toRfc4122(),
            ]);
            $parent->addEvent($parentEvent);
        }

        // Schedule ANAF submission based on company delay (cron picks it up)
        // null = disabled, user must submit manually
        $company = $invoice->getCompany();
        $delayHours = $company?->getEfacturaDelayHours();
        if ($delayHours !== null) {
            $invoice->setScheduledSendAt($this->computeSpvSubmissionTime($delayHours));
        }

        // Add document event
        $event = new DocumentEvent();
        $event->setPreviousStatus($previousStatus);
        $event->setNewStatus($newStatus);
        $event->setCreatedBy($user);
        $event->setMetadata([
            'action' => $isRefund ? 'refund_issued' : 'issued',
            'efacturaDelayHours' => $delayHours,
        ]);
        $invoice->addEvent($event);

        $this->entityManager->flush();
    }

    /**
     * Submit an already-issued invoice to ANAF immediately (manual trigger).
     */
    public function submitToAnaf(Invoice $invoice, User $user): void
    {
        $status = $invoice->getStatus();

        // Block draft, cancelled, refunded, and already-in-progress submissions
        $blockedStatuses = [DocumentStatus::DRAFT, DocumentStatus::CANCELLED, DocumentStatus::REFUNDED, DocumentStatus::SENT_TO_PROVIDER, DocumentStatus::SYNCED, DocumentStatus::VALIDATED];
        if (in_array($status, $blockedStatuses, true)) {
            throw new \DomainException('Factura nu poate fi trimisa in SPV (status: ' . $status->value . ').');
        }

        if ($status !== DocumentStatus::REJECTED && $invoice->getAnafUploadId()) {
            throw new \DomainException('Factura a fost deja trimisa la ANAF.');
        }

        // Check for valid ANAF token before proceeding
        $company = $invoice->getCompany();
        if ($company && $this->anafTokenResolver->resolve($company) === null) {
            throw new \DomainException('Nu exista un token ANAF valid pentru aceasta companie. Adaugati un token din Setari → ANAF.');
        }

        // Reset upload ID for rejected invoices being resubmitted
        if ($status === DocumentStatus::REJECTED) {
            $invoice->setAnafUploadId(null);
        }

        // Mark as sent_to_provider immediately to prevent editing during submission
        $invoice->setStatus(DocumentStatus::SENT_TO_PROVIDER);

        // Clear any scheduled send — we're sending now
        $invoice->setScheduledSendAt(null);

        // Add document event
        $event = new DocumentEvent();
        $event->setPreviousStatus($status);
        $event->setNewStatus(DocumentStatus::SENT_TO_PROVIDER);
        $event->setCreatedBy($user);
        $event->setMetadata(['action' => 'submitted_to_anaf']);
        $invoice->addEvent($event);

        $this->entityManager->flush();

        $this->messageBus->dispatch(
            new SubmitEInvoiceMessage(
                invoiceId: (string) $invoice->getId(),
                provider: EInvoiceProvider::ANAF->value,
            ),
        );
    }

    /**
     * Legacy: issue + auto-submit in one step (used by old /submit endpoint).
     */
    public function submit(Invoice $invoice, User $user): void
    {
        $this->issue($invoice, $user);
    }

    /**
     * Submit an invoice to a foreign e-invoicing provider (XRechnung, SDI, KSeF, Factur-X).
     */
    public function submitToEInvoice(Invoice $invoice, EInvoiceProvider $provider, User $user): void
    {
        $status = $invoice->getStatus();

        $blockedStatuses = [DocumentStatus::DRAFT, DocumentStatus::CANCELLED];
        if (in_array($status, $blockedStatuses, true)) {
            throw new \DomainException('Invoice cannot be submitted to e-invoicing (status: ' . $status->value . ').');
        }

        $event = new DocumentEvent();
        $event->setPreviousStatus($status);
        $event->setNewStatus($status);
        $event->setCreatedBy($user);
        $event->setMetadata([
            'action' => 'submitted_to_einvoice',
            'provider' => $provider->value,
        ]);
        $invoice->addEvent($event);

        $this->entityManager->flush();

        $this->messageBus->dispatch(
            new SubmitEInvoiceMessage(
                invoiceId: (string) $invoice->getId(),
                provider: $provider->value,
            )
        );
    }

    public function cancel(Invoice $invoice, ?string $reason, User $user): void
    {
        $status = $invoice->getStatus();

        if ($status === DocumentStatus::ISSUED && $invoice->getAnafUploadId() !== null) {
            throw new \DomainException('Factura a fost deja trimisa la ANAF si nu mai poate fi anulata.');
        }

        if (!in_array($status, [DocumentStatus::DRAFT, DocumentStatus::ISSUED], true)) {
            throw new \DomainException('Doar facturile ciorna sau emise pot fi anulate.');
        }

        $previousStatus = $status;
        $invoice->setStatus(DocumentStatus::CANCELLED);
        $invoice->setCancelledAt(new \DateTimeImmutable());
        $invoice->setCancellationReason($reason);
        $invoice->setScheduledSendAt(null);
        $invoice->setScheduledEmailAt(null);

        // Add document event
        $event = new DocumentEvent();
        $event->setPreviousStatus($previousStatus);
        $event->setNewStatus(DocumentStatus::CANCELLED);
        $event->setCreatedBy($user);
        $event->setMetadata([
            'action' => 'cancelled',
            'reason' => $reason,
            'cancelledFromStatus' => $previousStatus->value,
        ]);
        $invoice->addEvent($event);

        $this->entityManager->flush();
    }

    public function restore(Invoice $invoice, User $user): void
    {
        if ($invoice->getStatus() !== DocumentStatus::CANCELLED) {
            throw new \DomainException('Doar facturile anulate pot fi restaurate.');
        }

        $previousStatus = $invoice->getStatus();
        $invoice->setStatus(DocumentStatus::DRAFT);
        $invoice->setCancelledAt(null);
        $invoice->setCancellationReason(null);

        // Add document event
        $event = new DocumentEvent();
        $event->setPreviousStatus($previousStatus);
        $event->setNewStatus(DocumentStatus::DRAFT);
        $event->setCreatedBy($user);
        $event->setMetadata(['action' => 'restored']);
        $invoice->addEvent($event);

        $this->entityManager->flush();
    }

    private function setLines(Invoice $invoice, array $linesData): void
    {
        foreach ($linesData as $i => $lineData) {
            $line = new InvoiceLine();
            $this->populateLineFields($line, $lineData, $i + 1);
            $invoice->addLine($line);
        }
    }

    private function recalculateTotals(Invoice $invoice): void
    {
        $this->recalculateStoredTotals($invoice);
    }

    /**
     * Compute the SPV submission time within the 00:00-06:00 upload window (Romania time).
     */
    private function computeSpvSubmissionTime(int $delayHours): \DateTimeImmutable
    {
        $tz = new \DateTimeZone('Europe/Bucharest');
        $now = new \DateTimeImmutable('now', $tz);

        if ($delayHours >= 24) {
            // Day-based delays (24, 48, 72, 96h): schedule at midnight N days from now
            $days = intdiv($delayHours, 24);
            return $now->modify(sprintf('+%d days', $days))->setTime(0, 0, 0);
        }

        // Hour-based delay (e.g. 2h): add hours, then find next 00:00-06:00 window
        $earliest = $now->modify(sprintf('+%d hours', $delayHours));
        $hour = (int) $earliest->format('H');

        if ($hour < 6) {
            return $earliest; // already in 00:00-05:59 window
        }

        // next window: midnight tomorrow (Romania time)
        return $earliest->modify('+1 day')->setTime(0, 0, 0);
    }
}
