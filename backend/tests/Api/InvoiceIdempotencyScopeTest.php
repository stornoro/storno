<?php

namespace App\Tests\Api;

use App\Entity\Invoice;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The idempotency key of an invoice is scoped to the company.
 *
 * Shop plugins send keys built from the order number ("wc-100"), which repeat from one shop to
 * the next. A key used by one company must neither return that invoice to another company nor
 * stop the other company from creating its own.
 */
class InvoiceIdempotencyScopeTest extends ApiTestCase
{
    private function invoiceBody(string $key): array
    {
        return [
            'documentType' => 'invoice',
            'issueDate' => '2026-02-14',
            'dueDate' => '2026-03-14',
            'currency' => 'RON',
            'idempotencyKey' => $key,
            'lines' => [
                [
                    'description' => 'Sedinta meditatii',
                    'quantity' => '1.00',
                    'unitOfMeasure' => 'buc',
                    'unitPrice' => '100.00',
                    'vatRate' => '21.00',
                    'vatCategoryCode' => 'S',
                    'discount' => '0.00',
                    'discountPercent' => '0.00',
                ],
            ],
        ];
    }

    /** @return array{0: string, 1: string} */
    private function twoCompanies(): array
    {
        $companies = $this->apiGet('/api/v1/companies');
        $this->assertGreaterThanOrEqual(2, count($companies['data'] ?? []));

        return [$companies['data'][0]['id'], $companies['data'][1]['id']];
    }

    public function testSameKeyOnTheSameCompanyReturnsTheSameInvoice(): void
    {
        $this->login();
        [$companyA] = $this->twoCompanies();
        $key = 'test-scope-' . uniqid();

        $first = $this->apiPost('/api/v1/invoices', $this->invoiceBody($key), ['X-Company' => $companyA]);
        $this->assertResponseStatusCodeSame(201);
        $retry = $this->apiPost('/api/v1/invoices', $this->invoiceBody($key), ['X-Company' => $companyA]);
        $this->assertResponseStatusCodeSame(201);

        $this->assertSame($first['invoice']['id'], $retry['invoice']['id']);
    }

    public function testSameKeyOnAnotherCompanyCreatesItsOwnInvoice(): void
    {
        $this->login();
        [$companyA, $companyB] = $this->twoCompanies();
        $key = 'wc-' . uniqid();

        $a = $this->apiPost('/api/v1/invoices', $this->invoiceBody($key), ['X-Company' => $companyA]);
        $this->assertResponseStatusCodeSame(201);
        $b = $this->apiPost('/api/v1/invoices', $this->invoiceBody($key), ['X-Company' => $companyB]);
        $this->assertResponseStatusCodeSame(201);

        $this->assertNotSame($a['invoice']['id'], $b['invoice']['id'], 'Company B must not get the invoice of company A');

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $invoiceB = $em->getRepository(Invoice::class)->find(Uuid::fromString($b['invoice']['id']));
        $this->assertSame($companyB, $invoiceB->getCompany()->getId()->toRfc4122());

        // A retry on B returns the invoice of B.
        $retryB = $this->apiPost('/api/v1/invoices', $this->invoiceBody($key), ['X-Company' => $companyB]);
        $this->assertSame($b['invoice']['id'], $retryB['invoice']['id']);
    }

    public function testRetryWithCollectDoesNotRecordThePaymentTwice(): void
    {
        $this->login();
        [$companyA] = $this->twoCompanies();
        $key = 'test-collect-' . uniqid();

        $body = $this->invoiceBody($key);
        // Partial payment: the overpayment guard alone would let a second one through.
        $body['collect'] = ['value' => '40.00', 'type' => 'bank_transfer'];

        $first = $this->apiPost('/api/v1/invoices', $body, ['X-Company' => $companyA]);
        $this->assertResponseStatusCodeSame(201);
        $this->apiPost('/api/v1/invoices', $body, ['X-Company' => $companyA]);
        $this->assertResponseStatusCodeSame(201);

        $invoice = $this->apiGet('/api/v1/invoices/' . $first['invoice']['id'], ['X-Company' => $companyA]);
        $this->assertResponseIsSuccessful();
        $this->assertSame('40.00', $invoice['amountPaid'] ?? $invoice['invoice']['amountPaid'] ?? null);
    }
}
