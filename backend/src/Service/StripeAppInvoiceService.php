<?php

namespace App\Service;

use App\Entity\Client;
use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\StripeAppToken;
use App\Entity\User;
use App\Manager\InvoiceManager;
use App\Repository\ClientRepository;
use App\Repository\InvoiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\StripeClient;

class StripeAppInvoiceService
{
    public function __construct(
        private readonly InvoiceManager $invoiceManager,
        private readonly ClientRepository $clientRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly EntityManagerInterface $em,
        private readonly PaymentService $paymentService,
        private readonly LoggerInterface $logger,
        private readonly string $stripeSecretKey,
    ) {}

    public function createFromStripeInvoice(StripeAppToken $token, array $stripeInvoice): Invoice
    {
        $company = $token->getCompany();
        $user = $token->getUser();

        $stripeInvoiceId = $stripeInvoice['id'] ?? null;
        $idempotencyKey = 'stripe_app_' . $stripeInvoiceId;

        // Check for existing invoice with this idempotency key
        $existing = $this->invoiceRepository->findOneBy(['idempotencyKey' => $idempotencyKey]);
        if ($existing) {
            return $existing;
        }

        // Resolve or create client
        $client = $this->resolveOrCreateClient(
            $company,
            $stripeInvoice['customer_name'] ?? null,
            $stripeInvoice['customer_email'] ?? null,
            $stripeInvoice['customer_phone'] ?? null,
            $stripeInvoice['customer_tax_ids'] ?? [],
            $stripeInvoice['customer_address'] ?? [],
            $user,
        );

        // Build invoice lines from Stripe invoice lines
        $lines = [];
        $stripeLines = $stripeInvoice['lines']['data'] ?? [];
        foreach ($stripeLines as $line) {
            $unitPrice = ($line['unit_amount'] ?? $line['amount'] ?? 0) / 100;
            $quantity = $line['quantity'] ?? 1;

            $lines[] = [
                'description' => $line['description'] ?? 'Stripe invoice line',
                'quantity' => $quantity,
                'unitPrice' => (string) $unitPrice,
                'vatRate' => '19',
                'unitOfMeasure' => 'buc',
            ];
        }

        if (empty($lines)) {
            // Fallback: create single line from total
            $total = ($stripeInvoice['amount_due'] ?? $stripeInvoice['total'] ?? 0) / 100;
            $lines[] = [
                'description' => $stripeInvoice['description'] ?? 'Stripe invoice #' . ($stripeInvoice['number'] ?? $stripeInvoiceId),
                'quantity' => 1,
                'unitPrice' => (string) round($total / 1.19, 2),
                'vatRate' => '19',
                'unitOfMeasure' => 'buc',
            ];
        }

        $invoiceData = [
            'lines' => $lines,
            'currency' => strtoupper($stripeInvoice['currency'] ?? 'RON'),
            'idempotencyKey' => $idempotencyKey,
            // Stripe payments are card-based; default 'bank_transfer' is wrong.
            'paymentMethod' => 'card',
            // Lets the backend apply EU reverse charge / OSS rules automatically
            // based on the resolved client's VIES status — without this, all
            // invoices land at the company default VAT rate regardless of where
            // the customer sits.
            'autoApplyVatRules' => true,
        ];

        // Map Stripe's effective_at (or created) to issueDate so the invoice
        // reflects when Stripe finalised the bill, not whatever clock the
        // backend has when it gets around to creating it.
        $issueTimestamp = $stripeInvoice['effective_at']
            ?? $stripeInvoice['status_transitions']['finalized_at']
            ?? $stripeInvoice['created']
            ?? null;
        if ($issueTimestamp) {
            $invoiceData['issueDate'] = date('Y-m-d', $issueTimestamp);
        }

        // Public-facing notes: Stripe invoice number for cross-reference, plus
        // any description the merchant set on Stripe.
        $noteParts = [];
        if (!empty($stripeInvoice['number'])) {
            $noteParts[] = 'Stripe ' . $stripeInvoice['number'];
        }
        if (!empty($stripeInvoice['description'])) {
            $noteParts[] = $stripeInvoice['description'];
        }
        if ($noteParts) {
            $invoiceData['notes'] = implode(' — ', $noteParts);
        }

        // PDF language follows the connected user's Storno locale; defaults to
        // Romanian on the backend side.
        $userLocale = $user?->getLocale();
        if (in_array($userLocale, ['ro', 'en', 'de', 'fr'], true)) {
            $invoiceData['language'] = $userLocale;
        }

        if ($client) {
            $invoiceData['clientId'] = $client->getId()->toRfc4122();
        } else {
            $invoiceData['receiverName'] = $stripeInvoice['customer_name'] ?? 'Client Stripe';
            $invoiceData['receiverCif'] = $this->extractCif($stripeInvoice['customer_tax_ids'] ?? []);
        }

        if (!empty($stripeInvoice['due_date'])) {
            $invoiceData['dueDate'] = date('Y-m-d', $stripeInvoice['due_date']);
        }

        $invoice = $this->invoiceManager->create($company, $invoiceData, $user);

        // If auto mode, issue and submit to ANAF
        if ($token->isAutoMode()) {
            try {
                $this->invoiceManager->issue($invoice, $user);
                $this->invoiceManager->submitToAnaf($invoice, $user);
            } catch (\Exception $e) {
                $this->logger->warning('Stripe App: auto-submit to ANAF failed', [
                    'invoiceId' => $invoice->getId()->toRfc4122(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $invoice;
    }

    public function createFromStripeInvoiceId(StripeAppToken $token, string $stripeInvoiceId): Invoice
    {
        $stripe = new StripeClient($this->stripeSecretKey);

        $stripeInvoice = $stripe->invoices->retrieve($stripeInvoiceId, [
            'expand' => ['lines'],
        ], [
            'stripe_account' => $token->getStripeAccountId(),
        ]);

        return $this->createFromStripeInvoice($token, $stripeInvoice->toArray());
    }

    public function recordPaymentFromStripeInvoice(StripeAppToken $token, array $stripeInvoice): void
    {
        $stripeInvoiceId = $stripeInvoice['id'] ?? null;
        $idempotencyKey = 'stripe_app_' . $stripeInvoiceId;

        $invoice = $this->invoiceRepository->findOneBy(['idempotencyKey' => $idempotencyKey]);

        if (!$invoice) {
            $this->logger->info('Stripe App: no matching invoice for payment recording', [
                'stripeInvoiceId' => $stripeInvoiceId,
            ]);

            return;
        }

        $amountPaid = ($stripeInvoice['amount_paid'] ?? 0) / 100;

        if ($amountPaid <= 0) {
            return;
        }

        // Don't record if already fully paid
        if ((float) $invoice->getAmountPaid() >= (float) $invoice->getTotal()) {
            return;
        }

        try {
            $this->paymentService->recordPayment($invoice, [
                'amount' => (string) $amountPaid,
                'paymentMethod' => 'stripe',
                'reference' => 'stripe_invoice_' . $stripeInvoiceId,
                'paidAt' => (new \DateTimeImmutable())->format('Y-m-d'),
            ]);
        } catch (\Exception $e) {
            $this->logger->warning('Stripe App: payment recording failed', [
                'invoiceId' => $invoice->getId()->toRfc4122(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function resolveOrCreateClient(
        Company $company,
        ?string $name,
        ?string $email,
        ?string $phone,
        array $taxIds,
        array $address,
        User $user,
    ): ?Client {
        $cif = $this->extractCif($taxIds);
        $vatCode = $this->extractVatCode($taxIds);

        // Match by CIF first
        if ($cif) {
            $client = $this->clientRepository->findOneBy(['company' => $company, 'cui' => $cif]);
            if ($client) {
                return $client;
            }
        }

        // Match by email
        if ($email) {
            $client = $this->clientRepository->findOneBy(['company' => $company, 'email' => $email]);
            if ($client) {
                return $client;
            }
        }

        // Name is not an identity key — distinct payers can share a name.

        // Create new client if we have enough info
        if (!$name && !$email) {
            return null;
        }

        $client = new Client();
        $client->setCompany($company);
        $client->setName($name ?? $email);
        $client->setEmail($email);
        $client->setType($cif ? 'company' : 'individual');
        $client->setSource('stripe');

        if ($phone) {
            $client->setPhone($phone);
        }

        if ($cif) {
            $client->setCui($cif);
        }

        if ($vatCode) {
            $client->setVatCode($vatCode);
            $client->setIsVatPayer(true);
        }

        if (!empty($address['line1'])) {
            $client->setAddress($address['line1'] . (!empty($address['line2']) ? ', ' . $address['line2'] : ''));
        }
        if (!empty($address['city'])) {
            $client->setCity($address['city']);
        }
        if (!empty($address['state'])) {
            $client->setCounty($address['state']);
        }
        if (!empty($address['postal_code'])) {
            $client->setPostalCode($address['postal_code']);
        }

        // Country falls back to the prefix of the EU VAT number when the
        // customer's billing address has no country — common for Stripe
        // customers created via Checkout where VAT was collected separately.
        $country = $address['country'] ?? null;
        if (!$country && $vatCode && preg_match('/^([A-Z]{2})/', $vatCode, $m)) {
            $country = $m[1];
        }
        if ($country) {
            $client->setCountry($country);
        }

        $this->em->persist($client);
        $this->em->flush();

        return $client;
    }

    private function extractCif(array $taxIds): ?string
    {
        foreach ($taxIds as $taxId) {
            $type = $taxId['type'] ?? '';
            $value = $taxId['value'] ?? '';

            // Romanian VAT number: eu_vat with RO prefix, or ro_tin
            if ($type === 'eu_vat' && str_starts_with($value, 'RO')) {
                return ltrim(substr($value, 2), '0');
            }
            if ($type === 'ro_tin') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Stripe stores VAT numbers like "RO12345678" or "DE123456789" under
     * `eu_vat`. The Storno Client model keeps both `cui` (digits only) and
     * `vatCode` (full prefixed code) — the latter feeds VIES validation and
     * UBL XML generation, so it must include the country prefix.
     */
    private function extractVatCode(array $taxIds): ?string
    {
        foreach ($taxIds as $taxId) {
            $type = $taxId['type'] ?? '';
            $value = $taxId['value'] ?? '';

            if ($type === 'eu_vat' && preg_match('/^[A-Z]{2}\w+$/', $value)) {
                return $value;
            }
        }

        return null;
    }
}
