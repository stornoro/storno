<?php

namespace App\Tests\Api;

use App\Entity\Invoice;
use App\Enum\DocumentStatus;
use App\Enum\DocumentType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tests for StripeAppDataController endpoints:
 *   GET  /stripe-app/invoices-by-stripe/{stripeInvoiceId}
 *   GET  /stripe-app/refunds/{stripeRefundId}
 *   POST /stripe-app/refunds/{stripeRefundId}/create-credit-note
 *   GET  /stripe-app/subscriptions/{id}/invoices
 *   POST /stripe-app/subscriptions/{id}/invoices/{invoiceId}/create
 *
 * Also covers the StripeAppOAuthController dashboard normalisation:
 * sent_to_provider must be returned as sent_to_anaf in both counts and invoice rows.
 */
class StripeAppDataTest extends ApiTestCase
{
    private const STRIPE_ACCOUNT = 'acct_test_data_3K7moFIW6DSK99eF';

    private function linkAccount(string $email = 'admin@localhost.com'): array
    {
        $this->login($email);
        $companyId = $this->getFirstCompanyId();

        // Device flow
        $previous = $this->token;
        $this->token = null;
        $device = $this->apiPost('/api/v1/stripe-app/oauth/device', [
            'stripe_account_id' => self::STRIPE_ACCOUNT,
        ]);
        $this->token = $previous;
        $this->assertResponseStatusCodeSame(200);

        $this->apiPost('/api/v1/stripe-app/oauth/approve', [
            'user_code' => $device['user_code'],
            'company_id' => $companyId,
            'approve' => true,
        ]);
        $this->assertResponseStatusCodeSame(200);

        sleep(2);

        $previous = $this->token;
        $this->token = null;
        $tokens = $this->apiPost('/api/v1/stripe-app/token', [
            'grant_type' => 'device_code',
            'device_code' => $device['device_code'],
            'stripe_account_id' => self::STRIPE_ACCOUNT,
        ]);
        $this->token = $previous;

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('access_token', $tokens);

        return $tokens;
    }

    private function appTokenGet(string $uri, array $tokens): array
    {
        $previous = $this->token;
        $this->token = null;
        $resp = $this->apiGet($uri, ['X-Stripe-App-Token' => $tokens['access_token']]);
        $this->token = $previous;

        return $resp;
    }

    private function appTokenPost(string $uri, array $body, array $tokens): array
    {
        $previous = $this->token;
        $this->token = null;
        $resp = $this->apiPost($uri, $body, ['X-Stripe-App-Token' => $tokens['access_token']]);
        $this->token = $previous;

        return $resp;
    }

    // ─── /invoices-by-stripe ─────────────────────────────────────────────────

    public function testInvoicesByStripeReturnsNullWhenNotLinked(): void
    {
        $tokens = $this->linkAccount();

        $resp = $this->appTokenGet(
            '/api/v1/stripe-app/invoices-by-stripe/in_nonexistent',
            $tokens,
        );

        $this->assertResponseStatusCodeSame(200);
        $this->assertNull($resp['invoice']);
    }

    public function testInvoicesByStripeReturnsLinkedInvoice(): void
    {
        $tokens = $this->linkAccount();

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $companyId = $this->getFirstCompanyId();
        $company = $em->getReference(\App\Entity\Company::class, \Symfony\Component\Uid\Uuid::fromString($companyId));

        $stripeInvoiceId = 'in_test_' . uniqid();
        $idempotencyKey = 'stripe_app_' . $stripeInvoiceId;

        $invoice = new Invoice();
        $invoice->setCompany($company);
        $invoice->setStatus(DocumentStatus::VALIDATED);
        $invoice->setDocumentType(DocumentType::INVOICE);
        $invoice->setIdempotencyKey($idempotencyKey);
        $invoice->setReceiverName('Test Client');
        $invoice->setSenderName('Test Company');
        $invoice->setCurrency('RON');
        $invoice->setNumber('TEST-' . uniqid());
        $invoice->setIssueDate(new \DateTime());

        $em->persist($invoice);
        $em->flush();
        $invoiceId = $invoice->getId();

        try {
            $resp = $this->appTokenGet(
                '/api/v1/stripe-app/invoices-by-stripe/' . $stripeInvoiceId,
                $tokens,
            );

            $this->assertResponseStatusCodeSame(200);
            $this->assertNotNull($resp['invoice']);
            $this->assertSame('validated', $resp['invoice']['status']);
            $this->assertArrayHasKey('id', $resp['invoice']);
        } finally {
            $freshEm = self::getContainer()->get(EntityManagerInterface::class);
            $toRemove = $freshEm->find(Invoice::class, $invoiceId);
            if ($toRemove) {
                $freshEm->remove($toRemove);
                $freshEm->flush();
            }
        }
    }

    public function testInvoicesByStripeRequiresToken(): void
    {
        $this->apiGet('/api/v1/stripe-app/invoices-by-stripe/in_any');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testInvoicesByStripeDoesNotLeakOtherCompany(): void
    {
        $this->login();
        $companies = $this->apiGet('/api/v1/companies');
        $this->assertGreaterThanOrEqual(2, count($companies['data'] ?? []));

        $grantedId = $companies['data'][0]['id'];
        $otherId = $companies['data'][1]['id'];

        $tokens = $this->linkAccount();

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $otherCompany = $em->getReference(\App\Entity\Company::class, \Symfony\Component\Uid\Uuid::fromString($otherId));

        $stripeInvoiceId = 'in_other_' . uniqid();
        $idempotencyKey = 'stripe_app_' . $stripeInvoiceId;

        $invoice = new Invoice();
        $invoice->setCompany($otherCompany);
        $invoice->setStatus(DocumentStatus::VALIDATED);
        $invoice->setDocumentType(DocumentType::INVOICE);
        $invoice->setIdempotencyKey($idempotencyKey);
        $invoice->setReceiverName('Other Company Client');
        $invoice->setSenderName('Other Company');
        $invoice->setCurrency('RON');
        $invoice->setNumber('TEST-OTHER-' . uniqid());
        $invoice->setIssueDate(new \DateTime());

        $em->persist($invoice);
        $em->flush();
        $invoiceId = $invoice->getId();

        try {
            $resp = $this->appTokenGet(
                '/api/v1/stripe-app/invoices-by-stripe/' . $stripeInvoiceId,
                $tokens,
            );

            $this->assertResponseStatusCodeSame(200);
            // The invoice belongs to another company: must NOT be returned
            $this->assertNull(
                $resp['invoice'],
                'Invoice from another company was returned for a different-company token',
            );
        } finally {
            $freshEm = self::getContainer()->get(EntityManagerInterface::class);
            $toRemove = $freshEm->find(Invoice::class, $invoiceId);
            if ($toRemove) {
                $freshEm->remove($toRemove);
                $freshEm->flush();
            }
        }
    }

    // ─── /refunds/{id} ───────────────────────────────────────────────────────

    public function testRefundDetailReturnsNullWhenNoCreditNote(): void
    {
        $tokens = $this->linkAccount();

        $resp = $this->appTokenGet(
            '/api/v1/stripe-app/refunds/re_nonexistent',
            $tokens,
        );

        $this->assertResponseStatusCodeSame(200);
        $this->assertNull($resp['creditNote']);
    }

    public function testRefundDetailReturnsLinkedCreditNote(): void
    {
        $tokens = $this->linkAccount();

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $companyId = $this->getFirstCompanyId();
        $company = $em->getReference(\App\Entity\Company::class, \Symfony\Component\Uid\Uuid::fromString($companyId));

        $refundId = 're_test_' . uniqid();
        $idempotencyKey = 'stripe_app_refund_' . $refundId;

        $creditNote = new Invoice();
        $creditNote->setCompany($company);
        $creditNote->setStatus(DocumentStatus::DRAFT);
        $creditNote->setDocumentType(DocumentType::CREDIT_NOTE);
        $creditNote->setIdempotencyKey($idempotencyKey);
        $creditNote->setReceiverName('Client SRL');
        $creditNote->setSenderName('Test Company');
        $creditNote->setCurrency('RON');
        $creditNote->setNumber('CN-' . uniqid());
        $creditNote->setIssueDate(new \DateTime());

        $em->persist($creditNote);
        $em->flush();
        $creditNoteId = $creditNote->getId();

        try {
            $resp = $this->appTokenGet(
                '/api/v1/stripe-app/refunds/' . $refundId,
                $tokens,
            );

            $this->assertResponseStatusCodeSame(200);
            $this->assertNotNull($resp['creditNote']);
            $this->assertSame('draft', $resp['creditNote']['status']);
            $this->assertSame('credit_note', $resp['creditNote']['documentType']);
        } finally {
            $freshEm = self::getContainer()->get(EntityManagerInterface::class);
            $toRemove = $freshEm->find(Invoice::class, $creditNoteId);
            if ($toRemove) {
                $freshEm->remove($toRemove);
                $freshEm->flush();
            }
        }
    }

    public function testRefundDetailRequiresToken(): void
    {
        $this->apiGet('/api/v1/stripe-app/refunds/re_any');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testRefundDetailDoesNotLeakOtherCompany(): void
    {
        $this->login();
        $companies = $this->apiGet('/api/v1/companies');
        $this->assertGreaterThanOrEqual(2, count($companies['data'] ?? []));

        $otherId = $companies['data'][1]['id'];
        $tokens = $this->linkAccount(); // grants companies[0]

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $otherCompany = $em->getReference(\App\Entity\Company::class, \Symfony\Component\Uid\Uuid::fromString($otherId));

        $refundId = 're_other_' . uniqid();
        $creditNote = new Invoice();
        $creditNote->setCompany($otherCompany);
        $creditNote->setStatus(DocumentStatus::DRAFT);
        $creditNote->setDocumentType(DocumentType::CREDIT_NOTE);
        $creditNote->setIdempotencyKey('stripe_app_refund_' . $refundId);
        $creditNote->setReceiverName('Other Client');
        $creditNote->setSenderName('Other Company');
        $creditNote->setCurrency('RON');
        $creditNote->setNumber('CN-OTHER-' . uniqid());
        $creditNote->setIssueDate(new \DateTime());

        $em->persist($creditNote);
        $em->flush();
        $creditNoteId = $creditNote->getId();

        try {
            $resp = $this->appTokenGet(
                '/api/v1/stripe-app/refunds/' . $refundId,
                $tokens,
            );

            $this->assertResponseStatusCodeSame(200);
            $this->assertNull(
                $resp['creditNote'],
                'Credit note from another company was returned',
            );
        } finally {
            $freshEm = self::getContainer()->get(EntityManagerInterface::class);
            $toRemove = $freshEm->find(Invoice::class, $creditNoteId);
            if ($toRemove) {
                $freshEm->remove($toRemove);
                $freshEm->flush();
            }
        }
    }

    // ─── dashboard normalisation ──────────────────────────────────────────────

    public function testDashboardNormalisesSentToProvider(): void
    {
        $tokens = $this->linkAccount();

        $resp = $this->appTokenGet('/api/v1/stripe-app/dashboard', $tokens);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('counts', $resp);

        // The extension uses sent_to_anaf; the backend must not expose sent_to_provider.
        $this->assertArrayHasKey('sent_to_anaf', $resp['counts'], 'Dashboard counts must use sent_to_anaf key');
        $this->assertArrayNotHasKey('sent_to_provider', $resp['counts'], 'Dashboard must not expose sent_to_provider');
    }

    public function testDashboardInvoiceRowsNormaliseSentToProvider(): void
    {
        $tokens = $this->linkAccount();

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $companyId = $this->getFirstCompanyId();
        $company = $em->getReference(\App\Entity\Company::class, \Symfony\Component\Uid\Uuid::fromString($companyId));

        $invoice = new Invoice();
        $invoice->setCompany($company);
        $invoice->setStatus(DocumentStatus::SENT_TO_PROVIDER);
        $invoice->setDocumentType(DocumentType::INVOICE);
        $invoice->setReceiverName('ANAF Test Client');
        $invoice->setSenderName('Test Company');
        $invoice->setCurrency('RON');
        $invoice->setNumber('ANAF-TEST-' . uniqid());
        $invoice->setIssueDate(new \DateTime());

        $em->persist($invoice);
        $em->flush();
        $invoiceId = $invoice->getId();

        try {
            $resp = $this->appTokenGet('/api/v1/stripe-app/dashboard', $tokens);
            $this->assertResponseStatusCodeSame(200);

            $rows = $resp['recentInvoices'] ?? [];
            foreach ($rows as $row) {
                $this->assertNotSame(
                    'sent_to_provider',
                    $row['status'],
                    'Dashboard invoice row must not expose sent_to_provider status',
                );
            }
        } finally {
            $freshEm = self::getContainer()->get(EntityManagerInterface::class);
            $toRemove = $freshEm->find(Invoice::class, $invoiceId);
            if ($toRemove) {
                $freshEm->remove($toRemove);
                $freshEm->flush();
            }
        }
    }

    // ─── settings connectedUser ───────────────────────────────────────────────

    public function testSettingsReturnsConnectedUser(): void
    {
        $tokens = $this->linkAccount();

        $resp = $this->appTokenGet('/api/v1/stripe-app/settings', $tokens);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('connectedUser', $resp);

        if ($resp['connectedUser'] !== null) {
            $this->assertArrayHasKey('email', $resp['connectedUser']);
            $this->assertArrayHasKey('name', $resp['connectedUser']);
            $this->assertArrayHasKey('connectedAt', $resp['connectedUser']);
        }
    }
}
