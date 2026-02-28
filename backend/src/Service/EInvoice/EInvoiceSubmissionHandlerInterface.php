<?php

namespace App\Service\EInvoice;

use App\Entity\EInvoiceSubmission;
use App\Entity\Invoice;

/**
 * Strategy interface for provider-specific e-invoice submission logic.
 *
 * Each provider implementation is responsible for:
 * - Validating the invoice before submission
 * - Generating the provider-specific XML
 * - Storing the XML via OrganizationStorageResolver
 * - Optionally submitting via the provider API if credentials are configured
 * - Updating the EInvoiceSubmission entity with the outcome
 */
interface EInvoiceSubmissionHandlerInterface
{
    /**
     * Handle the full submission flow for this provider.
     *
     * Implementations must update $submission status and call
     * EntityManagerInterface::flush() before returning.
     */
    public function handle(Invoice $invoice, EInvoiceSubmission $submission): void;
}
