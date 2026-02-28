<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Purges all data belonging to a company.
 *
 * When adding a new entity with a company_id column, add it here.
 * Tables are deleted in FK-safe order (deepest children first).
 */
class CompanyDataPurger
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Delete all company-scoped data. Does NOT delete the company row itself.
     */
    public function purge(Connection $conn, string $companyId): void
    {
        $this->logger->info('CompanyDataPurger: Starting purge.', ['companyId' => $companyId]);
        // ── 1. Deep children (joined through parent) ──────────────
        $joinDeletes = [
            // invoice children
            ['invoice_line', 'invoice_id', 'invoice'],
            ['invoice_attachment', 'invoice_id', 'invoice'],
            ['document_event', 'invoice_id', 'invoice'],
            ['invoice_share_token', 'invoice_id', 'invoice'],
            // proforma children
            ['proforma_invoice_line', 'proforma_invoice_id', 'proforma_invoice'],
            // recurring children
            ['recurring_invoice_line', 'recurring_invoice_id', 'recurring_invoice'],
            // delivery note children
            ['delivery_note_line', 'delivery_note_id', 'delivery_note'],
            // receipt children
            ['receipt_line', 'receipt_id', 'receipt'],
            // email log children
            ['email_event', 'email_log_id', 'email_log'],
            // trial balance children
            ['trial_balance_row', 'trial_balance_id', 'trial_balance'],
        ];

        foreach ($joinDeletes as [$child, $fk, $parent]) {
            $conn->executeStatement(
                "DELETE c FROM `$child` c INNER JOIN `$parent` p ON c.`$fk` = p.id WHERE p.company_id = ?",
                [$companyId]
            );
        }

        // ── 2. Null out FKs referencing invoice ─────────────────────
        $conn->executeStatement(
            'UPDATE invoice SET parent_document_id = NULL WHERE company_id = ?',
            [$companyId]
        );
        $conn->executeStatement(
            'UPDATE receipt SET converted_invoice_id = NULL WHERE company_id = ?',
            [$companyId]
        );
        $conn->executeStatement(
            'UPDATE proforma_invoice SET converted_invoice_id = NULL WHERE company_id = ?',
            [$companyId]
        );
        $conn->executeStatement(
            'UPDATE delivery_note SET converted_invoice_id = NULL WHERE company_id = ?',
            [$companyId]
        );

        // ── 3. Company-level tables (order matters for FKs) ───────
        $companyTables = [
            'anaf_token_link',
            'borderou_transaction',
            'trial_balance',
            'import_job',
            'email_log',
            'email_template',
            'receipt',
            'proforma_invoice',
            'recurring_invoice',
            'delivery_note',
            'vat_rate',
            'payment',
            'efactura_message',
            'invoice',
            'client',
            'supplier',
            'product',
            'bank_account',
            'document_series',
        ];

        foreach ($companyTables as $table) {
            $conn->executeStatement(
                "DELETE FROM `$table` WHERE company_id = ?",
                [$companyId]
            );
        }

        $this->logger->info('CompanyDataPurger: Purge completed.', ['companyId' => $companyId]);
    }
}
