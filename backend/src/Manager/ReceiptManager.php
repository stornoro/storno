<?php

namespace App\Manager;

use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\Receipt;
use App\Entity\ReceiptLine;
use App\Entity\User;
use App\Enum\ReceiptStatus;
use App\Manager\Trait\DocumentCalculationTrait;
use App\Repository\ClientRepository;
use App\Repository\DocumentSeriesRepository;
use App\Repository\ReceiptRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class ReceiptManager
{
    use DocumentCalculationTrait;

    public function __construct(
        private readonly ReceiptRepository $receiptRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentSeriesRepository $documentSeriesRepository,
        private readonly ClientRepository $clientRepository,
        private readonly InvoiceManager $invoiceManager,
    ) {}

    public function find(string $uuid): ?Receipt
    {
        return $this->receiptRepository->findWithDetails($uuid);
    }

    public function listByCompany(Company $company, array $filters = [], int $page = 1, int $limit = 20): array
    {
        return $this->receiptRepository->findByCompanyPaginated($company, $filters, $page, $limit);
    }

    public function create(Company $company, array $data, User $user): Receipt
    {
        // Idempotency — return the existing receipt for repeat submissions of the
        // same logical sale. POS clients use this to safely retry queued offline
        // sales and ambiguous timeouts without producing duplicate fiscal receipts.
        if (!empty($data['idempotencyKey'])) {
            $existing = $this->receiptRepository->findOneBy(['idempotencyKey' => $data['idempotencyKey']]);
            if ($existing) {
                return $existing;
            }
        }

        $receipt = new Receipt();
        $receipt->setCompany($company);
        $receipt->setStatus(ReceiptStatus::DRAFT);
        $receipt->setIdempotencyKey($data['idempotencyKey'] ?? null);

        // Resolve client (optional for B2C)
        if (!empty($data['clientId'])) {
            $client = $this->clientRepository->find(Uuid::fromString($data['clientId']));
            if ($client) {
                $receipt->setClient($client);
            }
        }

        // Scalar fields
        if (isset($data['issueDate'])) {
            $receipt->setIssueDate(new \DateTime($data['issueDate']));
        }
        $receipt->setCurrency($data['currency'] ?? $company->getDefaultCurrency());
        $receipt->setNotes($data['notes'] ?? null);
        $receipt->setMentions($data['mentions'] ?? null);
        $receipt->setInternalNote($data['internalNote'] ?? null);
        $receipt->setProjectReference($data['projectReference'] ?? null);
        $receipt->setIssuerName($data['issuerName'] ?? null);
        $receipt->setIssuerId($data['issuerId'] ?? null);
        $receipt->setSalesAgent($data['salesAgent'] ?? null);
        $receipt->setExchangeRate($data['exchangeRate'] ?? null);

        // Receipt-specific fields
        $receipt->setPaymentMethod($data['paymentMethod'] ?? null);
        $receipt->setCashPayment($data['cashPayment'] ?? null);
        $receipt->setCardPayment($data['cardPayment'] ?? null);
        $receipt->setOtherPayment($data['otherPayment'] ?? null);
        $receipt->setCashRegisterName($data['cashRegisterName'] ?? null);
        $receipt->setFiscalNumber($data['fiscalNumber'] ?? null);
        $receipt->setCustomerName($data['customerName'] ?? null);
        $receipt->setCustomerCif($data['customerCif'] ?? null);

        // Document series
        if (!empty($data['documentSeriesId'])) {
            $series = $this->documentSeriesRepository->find(Uuid::fromString($data['documentSeriesId']));
            if ($series && $series->getCompany()?->getId()->equals($company->getId())) {
                $receipt->setDocumentSeries($series);
            }
        }
        if (!$receipt->getDocumentSeries()) {
            $defaultSeries = $this->documentSeriesRepository->findDefaultByType($company, 'receipt');
            if ($defaultSeries) {
                $receipt->setDocumentSeries($defaultSeries);
            }
        }

        // Draft receipts get a temporary number
        $receipt->setNumber('BON-' . substr(Uuid::v7()->toRfc4122(), 0, 8));

        // Create lines
        $this->setLines($receipt, $data['lines'] ?? []);

        // Recalculate totals
        $this->recalculateTotals($receipt);

        $this->entityManager->persist($receipt);
        $this->entityManager->flush();

        return $receipt;
    }

    public function update(Receipt $receipt, array $data, User $user): Receipt
    {
        if (!$receipt->isEditable()) {
            throw new \DomainException('Bonul fiscal nu poate fi editat.');
        }

        // Update scalar fields
        if (isset($data['issueDate'])) {
            $receipt->setIssueDate(new \DateTime($data['issueDate']));
        }
        if (isset($data['currency'])) {
            $receipt->setCurrency($data['currency']);
        }
        if (array_key_exists('notes', $data)) {
            $receipt->setNotes($data['notes']);
        }
        if (array_key_exists('mentions', $data)) {
            $receipt->setMentions($data['mentions']);
        }
        if (array_key_exists('internalNote', $data)) {
            $receipt->setInternalNote($data['internalNote']);
        }
        if (array_key_exists('projectReference', $data)) {
            $receipt->setProjectReference($data['projectReference']);
        }
        if (array_key_exists('issuerName', $data)) {
            $receipt->setIssuerName($data['issuerName']);
        }
        if (array_key_exists('issuerId', $data)) {
            $receipt->setIssuerId($data['issuerId']);
        }
        if (array_key_exists('salesAgent', $data)) {
            $receipt->setSalesAgent($data['salesAgent']);
        }
        if (array_key_exists('exchangeRate', $data)) {
            $receipt->setExchangeRate($data['exchangeRate']);
        }

        // Receipt-specific fields
        if (array_key_exists('paymentMethod', $data)) {
            $receipt->setPaymentMethod($data['paymentMethod']);
        }
        if (array_key_exists('cashPayment', $data)) {
            $receipt->setCashPayment($data['cashPayment']);
        }
        if (array_key_exists('cardPayment', $data)) {
            $receipt->setCardPayment($data['cardPayment']);
        }
        if (array_key_exists('otherPayment', $data)) {
            $receipt->setOtherPayment($data['otherPayment']);
        }
        if (array_key_exists('cashRegisterName', $data)) {
            $receipt->setCashRegisterName($data['cashRegisterName']);
        }
        if (array_key_exists('fiscalNumber', $data)) {
            $receipt->setFiscalNumber($data['fiscalNumber']);
        }
        if (array_key_exists('customerName', $data)) {
            $receipt->setCustomerName($data['customerName']);
        }
        if (array_key_exists('customerCif', $data)) {
            $receipt->setCustomerCif($data['customerCif']);
        }

        // Update document series
        if (isset($data['documentSeriesId'])) {
            if ($data['documentSeriesId']) {
                $series = $this->documentSeriesRepository->find(Uuid::fromString($data['documentSeriesId']));
                if ($series && $series->getCompany()?->getId()->equals($receipt->getCompany()->getId())) {
                    $receipt->setDocumentSeries($series);
                }
            } else {
                $receipt->setDocumentSeries(null);
            }
        }

        // Update client
        if (isset($data['clientId'])) {
            $client = $this->clientRepository->find(Uuid::fromString($data['clientId']));
            if ($client) {
                $receipt->setClient($client);
            }
        }

        // Replace lines
        if (isset($data['lines'])) {
            $receipt->clearLines();
            $this->entityManager->flush();
            $this->setLines($receipt, $data['lines']);
        }

        // Recalculate totals
        $this->recalculateTotals($receipt);

        $this->entityManager->flush();

        return $receipt;
    }

    public function delete(Receipt $receipt): void
    {
        if (!$receipt->isDeletable()) {
            throw new \DomainException('Bonul fiscal nu poate fi sters.');
        }

        $receipt->setDeletedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    public function issue(Receipt $receipt, User $user): void
    {
        if ($receipt->getStatus() !== ReceiptStatus::DRAFT) {
            throw new \DomainException('Doar bonurile ciorna pot fi emise.');
        }

        // Auto-assign default series if none set
        $series = $receipt->getDocumentSeries();
        if (!$series) {
            $series = $this->documentSeriesRepository->findDefaultByType(
                $receipt->getCompany(),
                'receipt'
            );
            if ($series) {
                $receipt->setDocumentSeries($series);
            }
        }

        // Assign final number from DocumentSeries with pessimistic lock
        if ($series) {
            $this->entityManager->wrapInTransaction(function () use ($series, $receipt) {
                $this->entityManager->lock($series, LockMode::PESSIMISTIC_WRITE);
                $this->entityManager->refresh($series);
                $newNumber = $series->getCurrentNumber() + 1;
                $series->setCurrentNumber($newNumber);
                $receipt->setNumber($series->getPrefix() . str_pad((string) $newNumber, 4, '0', STR_PAD_LEFT));
            });
        }

        $receipt->setStatus(ReceiptStatus::ISSUED);
        $receipt->setIssuedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    public function cancel(Receipt $receipt): void
    {
        if ($receipt->getStatus() === ReceiptStatus::INVOICED) {
            throw new \DomainException('Bonul facturat nu poate fi anulat.');
        }

        if ($receipt->getStatus() === ReceiptStatus::CANCELLED) {
            throw new \DomainException('Bonul este deja anulat.');
        }

        $receipt->setStatus(ReceiptStatus::CANCELLED);
        $receipt->setCancelledAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    public function restore(Receipt $receipt): void
    {
        if ($receipt->getStatus() !== ReceiptStatus::CANCELLED) {
            throw new \DomainException('Doar bonurile anulate pot fi restaurate.');
        }

        $receipt->setStatus(ReceiptStatus::DRAFT);
        $receipt->setCancelledAt(null);
        $this->entityManager->flush();
    }

    public function convertToInvoice(Receipt $receipt, Company $company, User $user): Invoice
    {
        if ($receipt->getStatus() !== ReceiptStatus::ISSUED) {
            throw new \DomainException('Doar bonurile emise pot fi convertite in factura.');
        }

        // Build invoice data from receipt
        $linesData = [];
        foreach ($receipt->getLines() as $line) {
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
            'currency' => $receipt->getCurrency(),
            'notes' => $receipt->getNotes(),
            'projectReference' => $receipt->getProjectReference(),
            'mentions' => $receipt->getMentions(),
            'internalNote' => $receipt->getInternalNote(),
            'salesAgent' => $receipt->getSalesAgent(),
            'issuerName' => $receipt->getIssuerName(),
            'issuerId' => $receipt->getIssuerId(),
            'exchangeRate' => $receipt->getExchangeRate(),
            'clientId' => $receipt->getClient() ? (string) $receipt->getClient()->getId() : null,
            'documentSeriesId' => $defaultInvoiceSeries ? (string) $defaultInvoiceSeries->getId() : null,
            'lines' => $linesData,
        ];

        $invoice = $this->invoiceManager->create($company, $invoiceData, $user);

        // Mark receipt as invoiced
        $receipt->setStatus(ReceiptStatus::INVOICED);
        $receipt->setConvertedInvoice($invoice);
        $this->entityManager->flush();

        return $invoice;
    }

    private function setLines(Receipt $receipt, array $linesData): void
    {
        foreach ($linesData as $i => $lineData) {
            $line = new ReceiptLine();
            $this->populateLineFields($line, $lineData, $i + 1);
            $receipt->addLine($line);
        }
    }

    private function recalculateTotals(Receipt $receipt): void
    {
        $this->recalculateStoredTotals($receipt);
    }

    /**
     * Create a refund (counter-)receipt that mirrors `parent`'s lines with
     * negative quantities. The refund is automatically issued and linked back
     * to the parent via refundOf. The parent must already be issued.
     *
     * When `lineSelections` is non-empty, only the selected source lines are
     * mirrored, each with the requested partial quantity (negated). The
     * resulting refund's payment amounts are scaled proportionally to the
     * refunded gross share so the cash-register impact stays consistent.
     *
     * @param array<int, array{sourceLineId: string, quantity: float|string}> $lineSelections
     *
     * @throws \DomainException when the parent isn't refundable or selections invalid.
     */
    public function refund(Receipt $parent, User $user, array $lineSelections = []): Receipt
    {
        if ($parent->getStatus() !== ReceiptStatus::ISSUED) {
            throw new \DomainException('Only issued receipts can be refunded.');
        }
        if ($parent->isRefund()) {
            throw new \DomainException('A refund receipt cannot itself be refunded.');
        }

        // Resolve and validate selections. An empty array means "refund the
        // whole receipt" (legacy/full-refund behaviour); a non-empty array
        // means "refund just these lines, possibly partially".
        $isPartial = count($lineSelections) > 0;
        $linesToRefund = [];  // [{sourceLine, qtyToRefund}]
        if ($isPartial) {
            // Index parent lines by id for O(1) lookup.
            $parentLines = [];
            foreach ($parent->getLines() as $pl) {
                $parentLines[(string) $pl->getId()] = $pl;
            }
            // Sum quantities already refunded per source line so we can validate
            // we don't over-refund across multiple partial passes. Cancelled
            // refunds release their quantities back to the pool.
            $alreadyRefunded = [];
            foreach ($parent->getActiveRefunds() as $existingRefund) {
                foreach ($existingRefund->getLines() as $rl) {
                    $key = trim((string) $rl->getDescription());
                    $alreadyRefunded[$key] = bcadd(
                        $alreadyRefunded[$key] ?? '0',
                        bcmul($rl->getQuantity(), '-1', 4),
                        4,
                    );
                }
            }
            foreach ($lineSelections as $selection) {
                $sourceLineId = (string) ($selection['sourceLineId'] ?? '');
                $reqQty = (string) ($selection['quantity'] ?? '0');
                if ($sourceLineId === '' || !isset($parentLines[$sourceLineId])) {
                    throw new \DomainException('Selected line does not belong to this receipt.');
                }
                if ((float) $reqQty <= 0) {
                    throw new \DomainException('Refund quantity must be positive.');
                }
                $sourceLine = $parentLines[$sourceLineId];
                $refunded = $alreadyRefunded[trim((string) $sourceLine->getDescription())] ?? '0';
                $remaining = bcsub($sourceLine->getQuantity(), $refunded, 4);
                if (bccomp($reqQty, $remaining, 4) > 0) {
                    throw new \DomainException(sprintf(
                        'Requested quantity (%s) exceeds remaining refundable quantity (%s) for line "%s".',
                        $reqQty,
                        $remaining,
                        $sourceLine->getDescription(),
                    ));
                }
                $linesToRefund[] = ['source' => $sourceLine, 'qty' => $reqQty];
            }
            if (count($linesToRefund) === 0) {
                throw new \DomainException('Select at least one line to refund.');
            }
        } else {
            if ($parent->isFullyRefunded()) {
                throw new \DomainException('Receipt has already been refunded.');
            }
            foreach ($parent->getLines() as $sourceLine) {
                $linesToRefund[] = ['source' => $sourceLine, 'qty' => $sourceLine->getQuantity()];
            }
        }

        // Compute the gross share being refunded so we can scale payment amounts.
        $parentGross = '0.00';
        foreach ($parent->getLines() as $pl) {
            $parentGross = bcadd($parentGross, $this->lineGross($pl), 2);
        }
        $refundGross = '0.00';
        foreach ($linesToRefund as $entry) {
            $refundGross = bcadd($refundGross, $this->lineGrossPartial($entry['source'], $entry['qty']), 2);
        }
        $share = $parentGross === '0.00'
            ? '1.0000'
            : bcdiv($refundGross, $parentGross, 6);

        $refund = new Receipt();
        $refund->setCompany($parent->getCompany());
        $refund->setStatus(ReceiptStatus::DRAFT);
        $refund->setRefundOf($parent);
        $refund->setClient($parent->getClient());
        $refund->setIssueDate(new \DateTime());
        $refund->setCurrency($parent->getCurrency());
        $refund->setExchangeRate($parent->getExchangeRate());
        $refund->setIssuerName($parent->getIssuerName());
        $refund->setIssuerId($parent->getIssuerId());
        $refund->setSalesAgent($parent->getSalesAgent());
        $refund->setNotes($parent->getNotes());
        $refund->setMentions($parent->getMentions());
        $refund->setInternalNote($parent->getInternalNote());
        $refund->setProjectReference($parent->getProjectReference());
        $refund->setCustomerName($parent->getCustomerName());
        $refund->setCustomerCif($parent->getCustomerCif());
        $refund->setCashRegisterName($parent->getCashRegisterName());
        $refund->setFiscalNumber($parent->getFiscalNumber());

        // Mirror payment method, with cash/card/other amounts flipped and
        // proportionally scaled when this is a partial refund.
        $refund->setPaymentMethod($parent->getPaymentMethod());
        if ($parent->getCashPayment() !== null) {
            $refund->setCashPayment($this->scaleNegative($parent->getCashPayment(), $share));
        }
        if ($parent->getCardPayment() !== null) {
            $refund->setCardPayment($this->scaleNegative($parent->getCardPayment(), $share));
        }
        if ($parent->getOtherPayment() !== null) {
            $refund->setOtherPayment($this->scaleNegative($parent->getOtherPayment(), $share));
        }

        // Inherit the same series so the refund's number is sequential alongside it.
        if ($parent->getDocumentSeries()) {
            $refund->setDocumentSeries($parent->getDocumentSeries());
        }
        $refund->setNumber('REFUND-' . substr(Uuid::v7()->toRfc4122(), 0, 8));

        // Mirror selected lines with negative quantities.
        $position = 1;
        foreach ($linesToRefund as $entry) {
            $sourceLine = $entry['source'];
            $absQty = (string) $entry['qty'];
            // Scale the line discount proportionally to the refunded fraction
            // of the source line so partial refunds get partial discount credit.
            $sourceQty = $sourceLine->getQuantity();
            $discountShare = bccomp($sourceQty, '0', 4) > 0
                ? bcdiv($absQty, $sourceQty, 6)
                : '1.000000';
            $lineDiscount = bcmul(
                $sourceLine->getDiscount() ?: '0.00',
                $discountShare,
                2,
            );
            $line = new ReceiptLine();
            $this->populateLineFields($line, [
                'description' => $sourceLine->getDescription(),
                'quantity' => bcmul($absQty, '-1', 4),
                'unitOfMeasure' => $sourceLine->getUnitOfMeasure(),
                'unitPrice' => $sourceLine->getUnitPrice(),
                'vatRate' => $sourceLine->getVatRate(),
                'vatCategoryCode' => $sourceLine->getVatCategoryCode(),
                'discount' => bcmul($lineDiscount, '-1', 2),
                'discountPercent' => $sourceLine->getDiscountPercent(),
                'vatIncluded' => $sourceLine->isVatIncluded(),
            ], $position++);
            $refund->addLine($line);
        }

        $this->recalculateTotals($refund);

        $this->entityManager->persist($refund);
        $this->entityManager->flush();

        // Auto-issue the refund — it represents a real money-out event.
        $this->issue($refund, $user);

        return $refund;
    }

    private function lineGross(\App\Entity\ReceiptLine $line): string
    {
        $gross = bcsub(
            bcmul($line->getQuantity(), $line->getUnitPrice(), 4),
            $line->getDiscount() ?: '0.00',
            2,
        );
        if (!$line->isVatIncluded()) {
            $rate = $line->getVatRate() ?: '0';
            $gross = bcmul($gross, bcadd('1', bcdiv($rate, '100', 6), 6), 2);
        }
        return $gross;
    }

    private function lineGrossPartial(\App\Entity\ReceiptLine $line, string $partialQty): string
    {
        // Reuse lineGross logic but with a clone-like math path on the partial qty.
        $gross = bcmul($partialQty, $line->getUnitPrice(), 2);
        $sourceQty = $line->getQuantity();
        if (bccomp($sourceQty, '0', 4) > 0) {
            $share = bcdiv($partialQty, $sourceQty, 6);
            $gross = bcsub($gross, bcmul($line->getDiscount() ?: '0.00', $share, 2), 2);
        }
        if (!$line->isVatIncluded()) {
            $rate = $line->getVatRate() ?: '0';
            $gross = bcmul($gross, bcadd('1', bcdiv($rate, '100', 6), 6), 2);
        }
        return $gross;
    }

    private function scaleNegative(string $amount, string $share): string
    {
        return bcmul(bcmul($amount, '-1', 6), $share, 2);
    }
}
