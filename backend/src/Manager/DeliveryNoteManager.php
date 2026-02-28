<?php

namespace App\Manager;

use App\Entity\Company;
use App\Entity\DeliveryNote;
use App\Entity\DeliveryNoteLine;
use App\Entity\Invoice;
use App\Entity\ProformaInvoice;
use App\Entity\User;
use App\Enum\DeliveryNoteStatus;
use App\Manager\Trait\DocumentCalculationTrait;
use App\Message\Anaf\SubmitETransportMessage;
use App\Repository\ClientRepository;
use App\Repository\DeliveryNoteRepository;
use App\Repository\DocumentSeriesRepository;
use App\Repository\ProformaInvoiceRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

class DeliveryNoteManager
{
    use DocumentCalculationTrait;

    public function __construct(
        private readonly DeliveryNoteRepository $deliveryNoteRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentSeriesRepository $documentSeriesRepository,
        private readonly ClientRepository $clientRepository,
        private readonly InvoiceManager $invoiceManager,
        private readonly ProformaInvoiceRepository $proformaInvoiceRepository,
        private readonly MessageBusInterface $messageBus,
    ) {}

    public function find(string $uuid): ?DeliveryNote
    {
        return $this->deliveryNoteRepository->findWithDetails($uuid);
    }

    public function listByCompany(Company $company, array $filters = [], int $page = 1, int $limit = 20): array
    {
        return $this->deliveryNoteRepository->findByCompanyPaginated($company, $filters, $page, $limit);
    }

    public function create(Company $company, array $data, User $user): DeliveryNote
    {
        $deliveryNote = new DeliveryNote();
        $deliveryNote->setCompany($company);
        $deliveryNote->setStatus(DeliveryNoteStatus::DRAFT);

        // Resolve client
        if (!empty($data['clientId'])) {
            $client = $this->clientRepository->find(Uuid::fromString($data['clientId']));
            if ($client) {
                $deliveryNote->setClient($client);
            }
        }

        // Scalar fields
        if (isset($data['issueDate'])) {
            $deliveryNote->setIssueDate(new \DateTime($data['issueDate']));
        }
        if (isset($data['dueDate'])) {
            $deliveryNote->setDueDate(new \DateTime($data['dueDate']));
        }
        $deliveryNote->setCurrency($data['currency'] ?? $company->getDefaultCurrency());
        $deliveryNote->setNotes($data['notes'] ?? null);
        $deliveryNote->setMentions($data['mentions'] ?? null);
        $deliveryNote->setInternalNote($data['internalNote'] ?? null);
        $deliveryNote->setDeliveryLocation($data['deliveryLocation'] ?? null);
        $deliveryNote->setProjectReference($data['projectReference'] ?? null);
        $deliveryNote->setIssuerName($data['issuerName'] ?? null);
        $deliveryNote->setIssuerId($data['issuerId'] ?? null);
        $deliveryNote->setSalesAgent($data['salesAgent'] ?? null);
        $deliveryNote->setDeputyName($data['deputyName'] ?? null);
        $deliveryNote->setDeputyIdentityCard($data['deputyIdentityCard'] ?? null);
        $deliveryNote->setDeputyAuto($data['deputyAuto'] ?? null);
        $deliveryNote->setExchangeRate($data['exchangeRate'] ?? null);

        // e-Transport fields
        $this->setETransportFields($deliveryNote, $data);

        // Assign document series
        $series = null;
        if (!empty($data['documentSeriesId'])) {
            $series = $this->documentSeriesRepository->find(Uuid::fromString($data['documentSeriesId']));
            if ($series && $series->getCompany()?->getId()->equals($company->getId())) {
                $deliveryNote->setDocumentSeries($series);
            }
        }
        if (!$deliveryNote->getDocumentSeries()) {
            $series = $this->documentSeriesRepository->findDefaultByType($company, 'delivery_note');
            if ($series) {
                $deliveryNote->setDocumentSeries($series);
            }
        }

        // Draft delivery notes get a temporary number
        $deliveryNote->setNumber('AVIZ-' . substr(Uuid::v7()->toRfc4122(), 0, 8));

        // Create lines
        $this->setLines($deliveryNote, $data['lines'] ?? []);

        // Recalculate totals
        $this->recalculateTotals($deliveryNote);

        $this->entityManager->persist($deliveryNote);
        $this->entityManager->flush();

        return $deliveryNote;
    }

    public function createFromProforma(ProformaInvoice $proforma, Company $company, User $user): DeliveryNote
    {
        $deliveryNote = new DeliveryNote();
        $deliveryNote->setCompany($company);
        $deliveryNote->setStatus(DeliveryNoteStatus::DRAFT);

        // Copy client
        if ($proforma->getClient()) {
            $deliveryNote->setClient($proforma->getClient());
        }

        // Copy dates
        $deliveryNote->setIssueDate(new \DateTime());
        if ($proforma->getDueDate()) {
            $deliveryNote->setDueDate(\DateTime::createFromInterface($proforma->getDueDate()));
        }

        // Copy scalar fields
        $deliveryNote->setCurrency($proforma->getCurrency());
        $deliveryNote->setNotes($proforma->getNotes());
        $deliveryNote->setMentions($proforma->getMentions());
        $deliveryNote->setInternalNote($proforma->getInternalNote());
        $deliveryNote->setDeliveryLocation($proforma->getDeliveryLocation());
        $deliveryNote->setProjectReference($proforma->getProjectReference());
        $deliveryNote->setIssuerName($proforma->getIssuerName());
        $deliveryNote->setIssuerId($proforma->getIssuerId());
        $deliveryNote->setSalesAgent($proforma->getSalesAgent());
        $deliveryNote->setExchangeRate($proforma->getExchangeRate());

        // Assign document series
        $defaultSeries = $this->documentSeriesRepository->findDefaultByType($company, 'delivery_note');
        if ($defaultSeries) {
            $deliveryNote->setDocumentSeries($defaultSeries);
        }

        // Assign temporary number
        $deliveryNote->setNumber('AVIZ-' . substr(Uuid::v7()->toRfc4122(), 0, 8));

        // Copy lines from proforma
        foreach ($proforma->getLines() as $proformaLine) {
            $line = new DeliveryNoteLine();
            $this->populateLineFields($line, [
                'description' => $proformaLine->getDescription(),
                'quantity' => $proformaLine->getQuantity(),
                'unitOfMeasure' => $proformaLine->getUnitOfMeasure(),
                'unitPrice' => $proformaLine->getUnitPrice(),
                'vatRate' => $proformaLine->getVatRate(),
                'vatCategoryCode' => $proformaLine->getVatCategoryCode(),
                'discount' => $proformaLine->getDiscount(),
                'discountPercent' => $proformaLine->getDiscountPercent(),
                'vatIncluded' => $proformaLine->isVatIncluded(),
                'productCode' => $proformaLine->getProductCode(),
            ], $proformaLine->getPosition());
            $deliveryNote->addLine($line);
        }

        // Recalculate totals
        $this->recalculateTotals($deliveryNote);

        $this->entityManager->persist($deliveryNote);
        $this->entityManager->flush();

        return $deliveryNote;
    }

    public function update(DeliveryNote $deliveryNote, array $data, User $user): DeliveryNote
    {
        if (!$deliveryNote->isEditable()) {
            throw new \DomainException('Avizul de insotire nu poate fi editat.');
        }

        // Update scalar fields
        if (isset($data['issueDate'])) {
            $deliveryNote->setIssueDate(new \DateTime($data['issueDate']));
        }
        if (isset($data['dueDate'])) {
            $deliveryNote->setDueDate(new \DateTime($data['dueDate']));
        }
        if (isset($data['currency'])) {
            $deliveryNote->setCurrency($data['currency']);
        }
        if (array_key_exists('notes', $data)) {
            $deliveryNote->setNotes($data['notes']);
        }
        if (array_key_exists('mentions', $data)) {
            $deliveryNote->setMentions($data['mentions']);
        }
        if (array_key_exists('internalNote', $data)) {
            $deliveryNote->setInternalNote($data['internalNote']);
        }
        if (array_key_exists('deliveryLocation', $data)) {
            $deliveryNote->setDeliveryLocation($data['deliveryLocation']);
        }
        if (array_key_exists('projectReference', $data)) {
            $deliveryNote->setProjectReference($data['projectReference']);
        }
        if (array_key_exists('issuerName', $data)) {
            $deliveryNote->setIssuerName($data['issuerName']);
        }
        if (array_key_exists('issuerId', $data)) {
            $deliveryNote->setIssuerId($data['issuerId']);
        }
        if (array_key_exists('salesAgent', $data)) {
            $deliveryNote->setSalesAgent($data['salesAgent']);
        }
        if (array_key_exists('deputyName', $data)) {
            $deliveryNote->setDeputyName($data['deputyName']);
        }
        if (array_key_exists('deputyIdentityCard', $data)) {
            $deliveryNote->setDeputyIdentityCard($data['deputyIdentityCard']);
        }
        if (array_key_exists('deputyAuto', $data)) {
            $deliveryNote->setDeputyAuto($data['deputyAuto']);
        }
        if (array_key_exists('exchangeRate', $data)) {
            $deliveryNote->setExchangeRate($data['exchangeRate']);
        }

        // e-Transport fields
        $this->setETransportFields($deliveryNote, $data);

        // Update document series
        if (isset($data['documentSeriesId'])) {
            if ($data['documentSeriesId']) {
                $series = $this->documentSeriesRepository->find(Uuid::fromString($data['documentSeriesId']));
                if ($series && $series->getCompany()?->getId()->equals($deliveryNote->getCompany()->getId())) {
                    $deliveryNote->setDocumentSeries($series);
                }
            } else {
                $deliveryNote->setDocumentSeries(null);
            }
        }

        // Update client
        if (isset($data['clientId'])) {
            $client = $this->clientRepository->find(Uuid::fromString($data['clientId']));
            if ($client) {
                $deliveryNote->setClient($client);
            }
        }

        // Replace lines
        if (isset($data['lines'])) {
            $deliveryNote->clearLines();
            $this->entityManager->flush();
            $this->setLines($deliveryNote, $data['lines']);
        }

        // Recalculate totals
        $this->recalculateTotals($deliveryNote);

        $this->entityManager->flush();

        return $deliveryNote;
    }

    public function delete(DeliveryNote $deliveryNote): void
    {
        if (!$deliveryNote->isDeletable()) {
            throw new \DomainException('Avizul de insotire nu poate fi sters.');
        }

        $deliveryNote->setDeletedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    public function issue(DeliveryNote $deliveryNote, User $user): void
    {
        if ($deliveryNote->getStatus() !== DeliveryNoteStatus::DRAFT) {
            throw new \DomainException('Doar avizele ciorna pot fi emise.');
        }

        // Auto-assign default series if none set
        $series = $deliveryNote->getDocumentSeries();
        if (!$series) {
            $series = $this->documentSeriesRepository->findDefaultByType(
                $deliveryNote->getCompany(),
                'delivery_note'
            );
            if ($series) {
                $deliveryNote->setDocumentSeries($series);
            }
        }

        // Assign final number from DocumentSeries with pessimistic lock
        if ($series) {
            $this->entityManager->wrapInTransaction(function () use ($series, $deliveryNote) {
                $this->entityManager->lock($series, LockMode::PESSIMISTIC_WRITE);
                $this->entityManager->refresh($series);
                $newNumber = $series->getCurrentNumber() + 1;
                $series->setCurrentNumber($newNumber);
                $deliveryNote->setNumber($series->getPrefix() . str_pad((string) $newNumber, 4, '0', STR_PAD_LEFT));
            });
        }

        $deliveryNote->setStatus(DeliveryNoteStatus::ISSUED);
        $deliveryNote->setIssuedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    public function cancel(DeliveryNote $deliveryNote): void
    {
        if ($deliveryNote->getStatus() === DeliveryNoteStatus::CONVERTED) {
            throw new \DomainException('Avizul convertit nu poate fi anulat.');
        }

        if ($deliveryNote->getStatus() === DeliveryNoteStatus::CANCELLED) {
            throw new \DomainException('Avizul este deja anulat.');
        }

        $etransportStatus = $deliveryNote->getEtransportStatus();
        if (in_array($etransportStatus, ['uploaded', 'ok'], true)) {
            throw new \DomainException('Avizul a fost trimis la e-Transport si nu mai poate fi anulat.');
        }

        $deliveryNote->setStatus(DeliveryNoteStatus::CANCELLED);
        $deliveryNote->setCancelledAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    public function restore(DeliveryNote $deliveryNote): void
    {
        if ($deliveryNote->getStatus() !== DeliveryNoteStatus::CANCELLED) {
            throw new \DomainException('Doar avizele anulate pot fi restaurate.');
        }

        $deliveryNote->setStatus(DeliveryNoteStatus::DRAFT);
        $deliveryNote->setCancelledAt(null);
        $this->entityManager->flush();
    }

    public function convertToInvoice(DeliveryNote $deliveryNote, Company $company, User $user): Invoice
    {
        if (!in_array($deliveryNote->getStatus(), [DeliveryNoteStatus::ISSUED], true)) {
            throw new \DomainException('Doar avizele emise pot fi convertite.');
        }

        // Build invoice data from delivery note
        $linesData = [];
        foreach ($deliveryNote->getLines() as $line) {
            $linesData[] = [
                'description' => $line->getDescription(),
                'quantity' => $line->getQuantity(),
                'unitOfMeasure' => $line->getUnitOfMeasure(),
                'unitPrice' => $line->getUnitPrice(),
                'vatRate' => $line->getVatRate(),
                'vatCategoryCode' => $line->getVatCategoryCode(),
                'discount' => $line->getDiscount(),
                'discountPercent' => $line->getDiscountPercent(),
                'vatIncluded' => $line->isVatIncluded(),
                'productCode' => $line->getProductCode(),
            ];
        }

        // Find default invoice series
        $defaultInvoiceSeries = $this->documentSeriesRepository->findDefaultByType($company, 'invoice');

        $invoiceData = [
            'documentType' => 'invoice',
            'issueDate' => (new \DateTime())->format('Y-m-d'),
            'dueDate' => $deliveryNote->getDueDate()?->format('Y-m-d'),
            'currency' => $deliveryNote->getCurrency(),
            'notes' => $deliveryNote->getNotes(),
            'deliveryLocation' => $deliveryNote->getDeliveryLocation(),
            'projectReference' => $deliveryNote->getProjectReference(),
            'mentions' => $deliveryNote->getMentions(),
            'internalNote' => $deliveryNote->getInternalNote(),
            'salesAgent' => $deliveryNote->getSalesAgent(),
            'issuerName' => $deliveryNote->getIssuerName(),
            'issuerId' => $deliveryNote->getIssuerId(),
            'deputyName' => $deliveryNote->getDeputyName(),
            'deputyIdentityCard' => $deliveryNote->getDeputyIdentityCard(),
            'deputyAuto' => $deliveryNote->getDeputyAuto(),
            'exchangeRate' => $deliveryNote->getExchangeRate(),
            'clientId' => $deliveryNote->getClient() ? (string) $deliveryNote->getClient()->getId() : null,
            'documentSeriesId' => $defaultInvoiceSeries ? (string) $defaultInvoiceSeries->getId() : null,
            'lines' => $linesData,
        ];

        $invoice = $this->invoiceManager->create($company, $invoiceData, $user);

        // Mark delivery note as converted
        $deliveryNote->setStatus(DeliveryNoteStatus::CONVERTED);
        $deliveryNote->setConvertedInvoice($invoice);
        $this->entityManager->flush();

        return $invoice;
    }

    public function bulkConvertToInvoice(array $deliveryNotes, Company $company, User $user): Invoice
    {
        if (empty($deliveryNotes)) {
            throw new \DomainException('Nu au fost selectate avize pentru conversie.');
        }

        // Validate all are ISSUED
        foreach ($deliveryNotes as $dn) {
            if ($dn->getStatus() !== DeliveryNoteStatus::ISSUED) {
                throw new \DomainException(sprintf(
                    'Avizul %s nu este emis. Doar avizele emise pot fi convertite.',
                    $dn->getNumber()
                ));
            }
        }

        // Validate all have the same currency
        $currencies = array_unique(array_map(fn(DeliveryNote $dn) => $dn->getCurrency(), $deliveryNotes));
        if (count($currencies) > 1) {
            throw new \DomainException('Toate avizele selectate trebuie sa aiba aceeasi moneda.');
        }

        // Validate all have the same client
        $clientIds = array_unique(array_map(
            fn(DeliveryNote $dn) => $dn->getClient() ? (string) $dn->getClient()->getId() : null,
            $deliveryNotes
        ));
        if (count($clientIds) > 1) {
            throw new \DomainException('Toate avizele selectate trebuie sa aiba acelasi client.');
        }

        // Merge lines from all delivery notes
        $linesData = [];
        $position = 1;
        foreach ($deliveryNotes as $dn) {
            foreach ($dn->getLines() as $line) {
                $linesData[] = [
                    'description' => $line->getDescription(),
                    'quantity' => $line->getQuantity(),
                    'unitOfMeasure' => $line->getUnitOfMeasure(),
                    'unitPrice' => $line->getUnitPrice(),
                    'vatRate' => $line->getVatRate(),
                    'vatCategoryCode' => $line->getVatCategoryCode(),
                    'discount' => $line->getDiscount(),
                    'discountPercent' => $line->getDiscountPercent(),
                    'vatIncluded' => $line->isVatIncluded(),
                    'productCode' => $line->getProductCode(),
                ];
                ++$position;
            }
        }

        // Find default invoice series
        $defaultInvoiceSeries = $this->documentSeriesRepository->findDefaultByType($company, 'invoice');

        $firstDn = $deliveryNotes[0];
        $invoiceData = [
            'documentType' => 'invoice',
            'issueDate' => (new \DateTime())->format('Y-m-d'),
            'currency' => $firstDn->getCurrency(),
            'clientId' => $firstDn->getClient() ? (string) $firstDn->getClient()->getId() : null,
            'documentSeriesId' => $defaultInvoiceSeries ? (string) $defaultInvoiceSeries->getId() : null,
            'lines' => $linesData,
        ];

        $invoice = $this->invoiceManager->create($company, $invoiceData, $user);

        // Mark all delivery notes as converted
        foreach ($deliveryNotes as $dn) {
            $dn->setStatus(DeliveryNoteStatus::CONVERTED);
            $dn->setConvertedInvoice($invoice);
        }

        $this->entityManager->flush();

        return $invoice;
    }

    public function storno(DeliveryNote $deliveryNote, Company $company, User $user): DeliveryNote
    {
        $stornoNote = new DeliveryNote();
        $stornoNote->setCompany($company);
        $stornoNote->setStatus(DeliveryNoteStatus::DRAFT);

        // Copy client and metadata
        $stornoNote->setClient($deliveryNote->getClient());
        $stornoNote->setIssueDate(new \DateTime());
        if ($deliveryNote->getDueDate()) {
            $stornoNote->setDueDate(\DateTime::createFromInterface($deliveryNote->getDueDate()));
        }
        $stornoNote->setCurrency($deliveryNote->getCurrency());
        $stornoNote->setNotes($deliveryNote->getNotes());
        $stornoNote->setMentions($deliveryNote->getMentions());
        $stornoNote->setInternalNote($deliveryNote->getInternalNote());
        $stornoNote->setDeliveryLocation($deliveryNote->getDeliveryLocation());
        $stornoNote->setProjectReference($deliveryNote->getProjectReference());
        $stornoNote->setIssuerName($deliveryNote->getIssuerName());
        $stornoNote->setIssuerId($deliveryNote->getIssuerId());
        $stornoNote->setSalesAgent($deliveryNote->getSalesAgent());
        $stornoNote->setDeputyName($deliveryNote->getDeputyName());
        $stornoNote->setDeputyIdentityCard($deliveryNote->getDeputyIdentityCard());
        $stornoNote->setDeputyAuto($deliveryNote->getDeputyAuto());
        $stornoNote->setExchangeRate($deliveryNote->getExchangeRate());

        // Assign document series
        $defaultSeries = $this->documentSeriesRepository->findDefaultByType($company, 'delivery_note');
        if ($defaultSeries) {
            $stornoNote->setDocumentSeries($defaultSeries);
        }

        // Assign temporary number
        $stornoNote->setNumber('AVIZ-' . substr(Uuid::v7()->toRfc4122(), 0, 8));

        // Copy lines with negated quantities
        foreach ($deliveryNote->getLines() as $srcLine) {
            $negatedQty = bcmul($srcLine->getQuantity(), '-1', 4);
            $line = new DeliveryNoteLine();
            $this->populateLineFields($line, [
                'description' => $srcLine->getDescription(),
                'quantity' => $negatedQty,
                'unitOfMeasure' => $srcLine->getUnitOfMeasure(),
                'unitPrice' => $srcLine->getUnitPrice(),
                'vatRate' => $srcLine->getVatRate(),
                'vatCategoryCode' => $srcLine->getVatCategoryCode(),
                'discount' => $srcLine->getDiscount(),
                'discountPercent' => $srcLine->getDiscountPercent(),
                'vatIncluded' => $srcLine->isVatIncluded(),
                'productCode' => $srcLine->getProductCode(),
            ], $srcLine->getPosition());
            $stornoNote->addLine($line);
        }

        $this->recalculateTotals($stornoNote);

        $this->entityManager->persist($stornoNote);
        $this->entityManager->flush();

        return $stornoNote;
    }

    public function submitToETransport(DeliveryNote $note, User $user): void
    {
        if ($note->getStatus() !== DeliveryNoteStatus::ISSUED) {
            throw new \DomainException('Doar avizele emise pot fi trimise la e-Transport.');
        }

        $allowedStatuses = [null, 'validation_failed', 'upload_failed', 'nok', 'pending_timeout'];
        if (!in_array($note->getEtransportStatus(), $allowedStatuses, true)) {
            throw new \DomainException('Avizul a fost deja trimis la e-Transport.');
        }

        $note->setEtransportStatus('pending');
        $note->setEtransportSubmittedAt(new \DateTimeImmutable());
        $note->setEtransportErrorMessage(null);
        $this->entityManager->flush();

        $this->messageBus->dispatch(
            new SubmitETransportMessage(deliveryNoteId: (string) $note->getId())
        );
    }

    private function setETransportFields(DeliveryNote $note, array $data): void
    {
        if (array_key_exists('etransportOperationType', $data)) {
            $note->setEtransportOperationType($data['etransportOperationType']);
        }
        if (array_key_exists('etransportPostIncident', $data)) {
            $note->setEtransportPostIncident($data['etransportPostIncident']);
        }
        if (array_key_exists('etransportVehicleNumber', $data)) {
            $note->setEtransportVehicleNumber($data['etransportVehicleNumber']);
        }
        if (array_key_exists('etransportTrailer1', $data)) {
            $note->setEtransportTrailer1($data['etransportTrailer1']);
        }
        if (array_key_exists('etransportTrailer2', $data)) {
            $note->setEtransportTrailer2($data['etransportTrailer2']);
        }
        if (array_key_exists('etransportTransporterCountry', $data)) {
            $note->setEtransportTransporterCountry($data['etransportTransporterCountry']);
        }
        if (array_key_exists('etransportTransporterCode', $data)) {
            $note->setEtransportTransporterCode($data['etransportTransporterCode']);
        }
        if (array_key_exists('etransportTransporterName', $data)) {
            $note->setEtransportTransporterName($data['etransportTransporterName']);
        }
        if (array_key_exists('etransportTransportDate', $data)) {
            $note->setEtransportTransportDate(
                $data['etransportTransportDate'] ? new \DateTime($data['etransportTransportDate']) : null
            );
        }
        if (array_key_exists('etransportStartCounty', $data)) {
            $note->setEtransportStartCounty($data['etransportStartCounty']);
        }
        if (array_key_exists('etransportStartLocality', $data)) {
            $note->setEtransportStartLocality($data['etransportStartLocality']);
        }
        if (array_key_exists('etransportStartStreet', $data)) {
            $note->setEtransportStartStreet($data['etransportStartStreet']);
        }
        if (array_key_exists('etransportStartNumber', $data)) {
            $note->setEtransportStartNumber($data['etransportStartNumber']);
        }
        if (array_key_exists('etransportStartOtherInfo', $data)) {
            $note->setEtransportStartOtherInfo($data['etransportStartOtherInfo']);
        }
        if (array_key_exists('etransportStartPostalCode', $data)) {
            $note->setEtransportStartPostalCode($data['etransportStartPostalCode']);
        }
        if (array_key_exists('etransportEndCounty', $data)) {
            $note->setEtransportEndCounty($data['etransportEndCounty']);
        }
        if (array_key_exists('etransportEndLocality', $data)) {
            $note->setEtransportEndLocality($data['etransportEndLocality']);
        }
        if (array_key_exists('etransportEndStreet', $data)) {
            $note->setEtransportEndStreet($data['etransportEndStreet']);
        }
        if (array_key_exists('etransportEndNumber', $data)) {
            $note->setEtransportEndNumber($data['etransportEndNumber']);
        }
        if (array_key_exists('etransportEndOtherInfo', $data)) {
            $note->setEtransportEndOtherInfo($data['etransportEndOtherInfo']);
        }
        if (array_key_exists('etransportEndPostalCode', $data)) {
            $note->setEtransportEndPostalCode($data['etransportEndPostalCode']);
        }
    }

    private function setLines(DeliveryNote $deliveryNote, array $linesData): void
    {
        foreach ($linesData as $i => $lineData) {
            $line = new DeliveryNoteLine();
            $this->populateLineFields($line, $lineData, $i + 1);

            // e-Transport line fields
            if (isset($lineData['tariffCode'])) {
                $line->setTariffCode($lineData['tariffCode']);
            }
            if (isset($lineData['purposeCode'])) {
                $line->setPurposeCode((int) $lineData['purposeCode']);
            }
            if (isset($lineData['unitOfMeasureCode'])) {
                $line->setUnitOfMeasureCode($lineData['unitOfMeasureCode']);
            }
            if (isset($lineData['netWeight'])) {
                $line->setNetWeight($lineData['netWeight']);
            }
            if (isset($lineData['grossWeight'])) {
                $line->setGrossWeight($lineData['grossWeight']);
            }
            if (isset($lineData['valueWithoutVat'])) {
                $line->setValueWithoutVat($lineData['valueWithoutVat']);
            }

            $deliveryNote->addLine($line);
        }
    }

    private function recalculateTotals(DeliveryNote $deliveryNote): void
    {
        $this->recalculateStoredTotals($deliveryNote);
    }
}
