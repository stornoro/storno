<?php

namespace App\Tests\Unit;

use App\Entity\Invoice;
use App\Service\PaymentService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Zero- and negative-total invoices (refunds, credit notes, gratis invoices)
 * are auto-settled: they carry nothing to collect or pay, so recalculation
 * must mark them paid and reject payment rows.
 */
class PaymentServiceTest extends TestCase
{
    private function makeService(string $paymentsSum = '0.00'): PaymentService
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $paymentRepository = $this->createMock(\App\Repository\PaymentRepository::class);
        $paymentRepository->method('sumByInvoice')->willReturn($paymentsSum);
        $paymentRepository->method('findByInvoice')->willReturn([]);

        return new PaymentService($entityManager, $paymentRepository);
    }

    public function testNegativeTotalInvoiceIsAutoSettled(): void
    {
        $invoice = new Invoice();
        $invoice->setTotal('-150.00');

        $this->makeService()->recalculateInvoicePaymentStatus($invoice);

        $this->assertEquals('-150.00', $invoice->getAmountPaid());
        $this->assertNotNull($invoice->getPaidAt());
        $this->assertEquals('0.00', $invoice->getBalance());
    }

    public function testZeroTotalInvoiceIsAutoSettled(): void
    {
        $invoice = new Invoice();
        $invoice->setTotal('0.00');

        $this->makeService()->recalculateInvoicePaymentStatus($invoice);

        $this->assertEquals('0.00', $invoice->getAmountPaid());
        $this->assertNotNull($invoice->getPaidAt());
        $this->assertEquals('0.00', $invoice->getBalance());
    }

    public function testAutoSettleKeepsExistingPaidAt(): void
    {
        $paidAt = new \DateTimeImmutable('2026-01-15');
        $invoice = new Invoice();
        $invoice->setTotal('-99.00');
        $invoice->setPaidAt($paidAt);

        $this->makeService()->recalculateInvoicePaymentStatus($invoice);

        $this->assertSame($paidAt, $invoice->getPaidAt());
    }

    public function testPositiveTotalInvoiceIsNotAutoSettled(): void
    {
        $invoice = new Invoice();
        $invoice->setTotal('100.00');

        $this->makeService()->recalculateInvoicePaymentStatus($invoice);

        $this->assertEquals('0.00', $invoice->getAmountPaid());
        $this->assertNull($invoice->getPaidAt());
    }

    public function testRecordPaymentRejectedForZeroTotalInvoice(): void
    {
        $invoice = new Invoice();
        $invoice->setTotal('0.00');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('automatically settled');

        $this->makeService()->recordPayment($invoice, ['amount' => '10.00']);
    }

    public function testRecordPaymentRejectedForNegativeTotalInvoice(): void
    {
        $invoice = new Invoice();
        $invoice->setTotal('-50.00');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('automatically settled');

        $this->makeService()->recordPayment($invoice, ['amount' => '10.00']);
    }
}
