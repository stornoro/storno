<?php

namespace App\Service;

use App\Entity\Company;
use App\Entity\DocumentEvent;
use App\Entity\Invoice;
use App\Entity\Payment;
use App\Entity\User;
use App\Enum\DocumentStatus;
use App\Repository\PaymentRepository;
use Doctrine\ORM\EntityManagerInterface;

class PaymentService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentRepository $paymentRepository,
    ) {}

    public function recordPayment(Invoice $invoice, array $data, ?User $user = null): Payment
    {
        $amount = $data['amount'] ?? '0.00';

        if (bccomp($amount, '0', 2) <= 0) {
            throw new \DomainException('Payment amount must be greater than zero.');
        }

        $balance = $invoice->getBalance();
        if (bccomp($amount, $balance, 2) > 0) {
            throw new \DomainException('Payment amount cannot exceed the remaining balance of ' . $balance . '.');
        }

        $payment = new Payment();
        $payment->setInvoice($invoice);
        $payment->setCompany($invoice->getCompany());
        $payment->setAmount($amount);
        $payment->setCurrency($data['currency'] ?? $invoice->getCurrency());
        $payment->setPaymentMethod($data['paymentMethod'] ?? 'bank_transfer');
        $payment->setReference($data['reference'] ?? null);
        $payment->setNotes($data['notes'] ?? null);

        if (isset($data['paymentDate'])) {
            $payment->setPaymentDate(new \DateTime($data['paymentDate']));
        }

        $previousStatus = $invoice->getStatus();

        $this->entityManager->persist($payment);
        // Flush payment first so sumByInvoice reads it from DB
        $this->entityManager->flush();

        $this->recalculateInvoicePaymentStatus($invoice);

        // Record event if status changed
        $this->addPaymentEvent($invoice, $previousStatus, $user, [
            'action' => 'payment_recorded',
            'paymentId' => (string) $payment->getId(),
            'amount' => $amount,
            'paymentMethod' => $payment->getPaymentMethod(),
            'amountPaid' => $invoice->getAmountPaid(),
            'balance' => $invoice->getBalance(),
        ]);

        $this->entityManager->flush();

        return $payment;
    }

    public function deletePayment(Payment $payment, ?User $user = null): void
    {
        $invoice = $payment->getInvoice();
        $previousStatus = $invoice?->getStatus();
        $deletedAmount = $payment->getAmount();

        $this->entityManager->remove($payment);

        if ($invoice) {
            $invoice->removePayment($payment);
        }

        // Flush the remove first so sumByInvoice reads correct totals
        $this->entityManager->flush();

        if ($invoice) {
            $this->recalculateInvoicePaymentStatus($invoice);

            $this->addPaymentEvent($invoice, $previousStatus, $user, [
                'action' => 'payment_deleted',
                'amount' => $deletedAmount,
                'amountPaid' => $invoice->getAmountPaid(),
                'balance' => $invoice->getBalance(),
            ]);

            $this->entityManager->flush();
        }
    }

    public function deleteAllPayments(Invoice $invoice, ?User $user = null): void
    {
        $previousStatus = $invoice->getStatus();
        $payments = $this->paymentRepository->findByInvoice($invoice);
        $count = count($payments);

        foreach ($payments as $payment) {
            $this->entityManager->remove($payment);
        }

        // Flush removes first so sumByInvoice reads zero
        $this->entityManager->flush();

        $this->recalculateInvoicePaymentStatus($invoice);

        if ($count > 0) {
            $this->addPaymentEvent($invoice, $previousStatus, $user, [
                'action' => 'all_payments_deleted',
                'count' => $count,
                'amountPaid' => $invoice->getAmountPaid(),
                'balance' => $invoice->getBalance(),
            ]);
        }

        $this->entityManager->flush();
    }

    public function recalculateInvoicePaymentStatus(Invoice $invoice): void
    {
        $totalPaid = $this->paymentRepository->sumByInvoice($invoice);
        $invoice->setAmountPaid($totalPaid);

        $total = $invoice->getTotal();

        if (bccomp($totalPaid, $total, 2) >= 0) {
            // Fully paid — set paidAt and paymentMethod
            $payments = $this->paymentRepository->findByInvoice($invoice);
            $latestPayment = $payments[0] ?? null;

            $paidAt = $latestPayment?->getPaymentDate();
            if ($paidAt instanceof \DateTime) {
                $paidAt = \DateTimeImmutable::createFromMutable($paidAt);
            }
            $invoice->setPaidAt($paidAt ?? new \DateTimeImmutable());
            $invoice->setPaymentMethod($latestPayment?->getPaymentMethod());
        } elseif (bccomp($totalPaid, '0', 2) > 0) {
            // Partially paid — clear paidAt (not fully paid yet)
            $invoice->setPaidAt(null);
        } else {
            // Zero paid — clear payment fields
            $invoice->setPaidAt(null);
            $invoice->setPaymentMethod(null);
        }
    }

    public function getPaymentSummary(Company $company, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $conn = $this->entityManager->getConnection();
        $companyId = (string) $company->getId();

        $dateFilter = '';
        $dateParams = [];
        if ($dateFrom) {
            $dateFilter .= ' AND issue_date >= :dateFrom';
            $dateParams['dateFrom'] = $dateFrom;
        }
        if ($dateTo) {
            $dateFilter .= ' AND issue_date <= :dateTo';
            $dateParams['dateTo'] = $dateTo;
        }

        $params = array_merge(['companyId' => $companyId], $dateParams);

        $outstanding = $conn->fetchAssociative(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(total - amount_paid), 0) AS amount
             FROM invoice
             WHERE company_id = :companyId
               AND deleted_at IS NULL
               AND direction = 'outgoing'
               AND paid_at IS NULL
               AND status NOT IN ('draft', 'cancelled')" . $dateFilter,
            $params
        );

        $overdue = $conn->fetchAssociative(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(total - amount_paid), 0) AS amount
             FROM invoice
             WHERE company_id = :companyId
               AND deleted_at IS NULL
               AND direction = 'outgoing'
               AND paid_at IS NULL
               AND due_date < CURRENT_DATE
               AND status NOT IN ('draft', 'cancelled')" . $dateFilter,
            $params
        );

        return [
            'outstandingCount' => (int) ($outstanding['cnt'] ?? 0),
            'outstandingAmount' => $outstanding['amount'] ?? '0.00',
            'overdueCount' => (int) ($overdue['cnt'] ?? 0),
            'overdueAmount' => $overdue['amount'] ?? '0.00',
        ];
    }

    private function addPaymentEvent(
        Invoice $invoice,
        ?DocumentStatus $previousStatus,
        ?User $user,
        array $metadata,
    ): void {
        $event = new DocumentEvent();
        $event->setPreviousStatus($previousStatus);
        $event->setNewStatus($invoice->getStatus());
        $event->setCreatedBy($user);
        $event->setMetadata($metadata);
        $invoice->addEvent($event);
    }
}
