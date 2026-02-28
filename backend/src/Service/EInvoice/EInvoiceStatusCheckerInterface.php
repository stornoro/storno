<?php

namespace App\Service\EInvoice;

use App\Entity\EInvoiceSubmission;
use App\Message\EInvoice\CheckEInvoiceStatusMessage;

/**
 * Strategy interface for provider-specific e-invoice status checking logic.
 *
 * Each provider implementation is responsible for:
 * - Calling the provider API to retrieve the current processing status
 * - Updating the EInvoiceSubmission entity with the response
 * - Scheduling a follow-up status check via MessageBusInterface if still pending
 * - Syncing provider-specific fields back to the Invoice entity (e.g. ANAF fields)
 * - Calling EntityManagerInterface::flush() after any state change
 */
interface EInvoiceStatusCheckerInterface
{
    /**
     * Check the current processing status for the given submission.
     *
     * The $message carries the current attempt count and submission ID so that
     * implementations can apply per-provider retry/backoff strategies.
     */
    public function check(EInvoiceSubmission $submission, CheckEInvoiceStatusMessage $message): void;
}
