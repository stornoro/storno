<?php

namespace App\Service;

use App\Entity\Company;
use App\Entity\Organization;
use App\Manager\InvoiceManager;
use App\Repository\CompanyRepository;
use App\Repository\DocumentSeriesRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Generates Storno.ro invoices for subscription payments (SaaS only).
 *
 * When BILLING_COMPANY_ID is set, this service creates invoices
 * from that company (Storno.ro) to the subscribing organization.
 * When empty (self-hosted), all methods are no-ops.
 */
class BillingInvoiceService
{
    private ?Company $billingCompany = null;
    private bool $resolved = false;

    public function __construct(
        private readonly InvoiceManager $invoiceManager,
        private readonly CompanyRepository $companyRepository,
        private readonly DocumentSeriesRepository $documentSeriesRepository,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly string $billingCompanyId,
    ) {}

    /**
     * Create an invoice for a successful subscription payment.
     *
     * @param Organization $org       The subscribing organization (buyer)
     * @param string       $planName  Plan name (pro, business)
     * @param int          $amount    Amount in smallest currency unit (e.g. bani for RON)
     * @param string       $currency  Currency code (e.g. RON)
     * @param string       $interval  Billing interval (month, year)
     * @param string       $stripeInvoiceId Stripe invoice ID for reference
     */
    public function createSubscriptionInvoice(
        Organization $org,
        string $planName,
        int $amount,
        string $currency,
        string $interval,
        string $stripeInvoiceId,
    ): void {
        $company = $this->getBillingCompany();
        if (!$company) {
            return; // Self-hosted or not configured — skip
        }

        // Prevent duplicate invoices for the same Stripe invoice
        $idempotencyKey = 'stripe_' . $stripeInvoiceId;

        // Resolve document series for billing (look for a series with prefix "AF" or the first active one)
        $series = $this->documentSeriesRepository->findOneBy([
            'company' => $company,
            'type' => 'invoice',
            'active' => true,
            'source' => 'billing',
        ]);
        if (!$series) {
            $series = $this->documentSeriesRepository->findOneBy([
                'company' => $company,
                'type' => 'invoice',
                'active' => true,
            ]);
        }

        $periodLabel = $interval === 'year' ? 'an' : 'luna';
        $planLabel = ucfirst($planName);
        $unitPrice = number_format($amount / 100, 2, '.', '');

        // Determine VAT: Storno.ro (RO company) charges 19% TVA to RO customers
        $vatRate = '19.00';
        $vatCategoryCode = 'S';

        // Build receiver info from the organization
        $receiverName = $org->getName();
        // Try to find the org's first company for CIF
        $orgCompanies = $org->getCompanies();
        $receiverCif = null;
        if ($orgCompanies->count() > 0) {
            $receiverCif = (string) $orgCompanies->first()->getCif();
        }

        $data = [
            'issueDate' => (new \DateTime())->format('Y-m-d'),
            'dueDate' => (new \DateTime())->format('Y-m-d'), // Already paid
            'currency' => strtoupper($currency),
            'receiverName' => $receiverName,
            'receiverCif' => $receiverCif,
            'notes' => sprintf('Plata procesata prin Stripe. Referinta: %s', $stripeInvoiceId),
            'paymentTerms' => 'Platit',
            'idempotencyKey' => $idempotencyKey,
            'lines' => [
                [
                    'description' => sprintf('Abonament Storno.ro %s — %s', $planLabel, $periodLabel),
                    'quantity' => '1.0000',
                    'unitOfMeasure' => 'buc',
                    'unitPrice' => $unitPrice,
                    'vatRate' => $vatRate,
                    'vatCategoryCode' => $vatCategoryCode,
                    'discount' => '0.00',
                    'discountPercent' => '0.00',
                ],
            ],
        ];

        if ($series) {
            $data['documentSeriesId'] = (string) $series->getId();
        }

        try {
            // We need a system user for the event — use the company creator
            $user = $company->getCreatedBy();
            if (!$user) {
                $this->logger->error('Billing company has no createdBy user, cannot create invoice');
                return;
            }

            $invoice = $this->invoiceManager->create($company, $data, $user);

            // Issue the invoice (assigns final number from series)
            $this->invoiceManager->issue($invoice, $user);

            // Mark as paid immediately
            $invoice->setPaidAt(new \DateTimeImmutable());
            $invoice->setPaymentMethod('stripe');
            $invoice->setAmountPaid($invoice->getTotal());
            $this->em->flush();

            $this->logger->info('Billing invoice created for subscription payment', [
                'invoiceId' => (string) $invoice->getId(),
                'invoiceNumber' => $invoice->getNumber(),
                'organization' => (string) $org->getId(),
                'stripeInvoiceId' => $stripeInvoiceId,
                'amount' => $unitPrice,
                'currency' => $currency,
            ]);
        } catch (\Throwable $e) {
            // Log but don't fail the webhook — subscription should still activate
            $this->logger->error('Failed to create billing invoice', [
                'organization' => (string) $org->getId(),
                'stripeInvoiceId' => $stripeInvoiceId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function getBillingCompany(): ?Company
    {
        if ($this->resolved) {
            return $this->billingCompany;
        }

        $this->resolved = true;

        if (empty($this->billingCompanyId)) {
            return null;
        }

        try {
            $this->billingCompany = $this->companyRepository->find(Uuid::fromString($this->billingCompanyId));
            if (!$this->billingCompany) {
                $this->logger->warning('BILLING_COMPANY_ID is set but company not found', [
                    'billingCompanyId' => $this->billingCompanyId,
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Invalid BILLING_COMPANY_ID', [
                'billingCompanyId' => $this->billingCompanyId,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->billingCompany;
    }
}
