<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:company:clear-invoices',
    description: 'Permanently delete all invoices and related data for a company',
)]
class ClearCompanyInvoicesCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('company-id', InputArgument::REQUIRED, 'The company UUID')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview counts without deleting')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $companyId = $input->getArgument('company-id');
        $dryRun = $input->getOption('dry-run');
        $force = $input->getOption('force');

        // Verify company exists
        $company = $this->connection->fetchAssociative(
            'SELECT id, name, cif FROM company WHERE id = ?',
            [$companyId],
        );

        if (!$company) {
            $io->error(sprintf('Company not found: %s', $companyId));
            return Command::FAILURE;
        }

        $io->title(sprintf('Clear invoices for: %s (CIF %s)', $company['name'], $company['cif']));

        // Count what will be deleted (including soft-deleted invoices)
        $invoiceCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM invoice WHERE company_id = ?',
            [$companyId],
        );

        if ($invoiceCount === 0) {
            $io->success('No invoices found for this company.');
            return Command::SUCCESS;
        }

        $counts = $this->getCounts($companyId);

        $io->table(
            ['Table', 'Records'],
            array_map(fn ($table, $count) => [$table, $count], array_keys($counts), array_values($counts)),
        );

        if ($dryRun) {
            $io->note('Dry-run mode — nothing was deleted.');
            return Command::SUCCESS;
        }

        if (!$force) {
            $confirmed = $io->confirm(
                sprintf('This will PERMANENTLY delete %d invoices and all related data. Continue?', $invoiceCount),
                false,
            );

            if (!$confirmed) {
                $io->warning('Aborted.');
                return Command::SUCCESS;
            }
        }

        $this->connection->beginTransaction();

        try {
            $deleted = $this->deleteAll($companyId);

            $this->connection->commit();

            $io->newLine();
            $io->table(
                ['Table', 'Deleted'],
                array_map(fn ($table, $count) => [$table, $count], array_keys($deleted), array_values($deleted)),
            );

            $io->success('All invoice data cleared.');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            $io->error(sprintf('Failed: %s', $e->getMessage()));
            return Command::FAILURE;
        }
    }

    private function getCounts(string $companyId): array
    {
        $invoiceSubquery = 'SELECT id FROM invoice WHERE company_id = ?';

        return [
            'invoice' => (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM invoice WHERE company_id = ?',
                [$companyId],
            ),
            'invoice_line' => (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM invoice_line WHERE invoice_id IN ($invoiceSubquery)",
                [$companyId],
            ),
            'payment' => (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM payment WHERE invoice_id IN ($invoiceSubquery)",
                [$companyId],
            ),
            'invoice_attachment' => (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM invoice_attachment WHERE invoice_id IN ($invoiceSubquery)",
                [$companyId],
            ),
            'document_event' => (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM document_event WHERE invoice_id IN ($invoiceSubquery)",
                [$companyId],
            ),
            'email_log' => (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM email_log WHERE invoice_id IN ($invoiceSubquery)",
                [$companyId],
            ),
            'efactura_message' => (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM efactura_message WHERE invoice_id IN ($invoiceSubquery)",
                [$companyId],
            ),
        ];
    }

    private function deleteAll(string $companyId): array
    {
        $invoiceSubquery = 'SELECT id FROM invoice WHERE company_id = ?';
        $deleted = [];

        // 1. Unlink proforma_invoice.converted_invoice_id
        $this->connection->executeStatement(
            "UPDATE proforma_invoice SET converted_invoice_id = NULL WHERE converted_invoice_id IN ($invoiceSubquery)",
            [$companyId],
        );

        // 2. Unlink delivery_note.converted_invoice_id
        $this->connection->executeStatement(
            "UPDATE delivery_note SET converted_invoice_id = NULL WHERE converted_invoice_id IN ($invoiceSubquery)",
            [$companyId],
        );

        // 3. Delete document_event (no cascade from invoice)
        $deleted['document_event'] = $this->connection->executeStatement(
            "DELETE FROM document_event WHERE invoice_id IN ($invoiceSubquery)",
            [$companyId],
        );

        // 4. Nullify email_log references (keep logs, unlink invoice)
        $deleted['email_log (unlinked)'] = $this->connection->executeStatement(
            "UPDATE email_log SET invoice_id = NULL WHERE invoice_id IN ($invoiceSubquery)",
            [$companyId],
        );

        // 5. Nullify efactura_message references
        $deleted['efactura_message (unlinked)'] = $this->connection->executeStatement(
            "UPDATE efactura_message SET invoice_id = NULL WHERE invoice_id IN ($invoiceSubquery)",
            [$companyId],
        );

        // 6. Delete invoice_attachment
        $deleted['invoice_attachment'] = $this->connection->executeStatement(
            "DELETE FROM invoice_attachment WHERE invoice_id IN ($invoiceSubquery)",
            [$companyId],
        );

        // 7. Delete payment
        $deleted['payment'] = $this->connection->executeStatement(
            "DELETE FROM payment WHERE invoice_id IN ($invoiceSubquery)",
            [$companyId],
        );

        // 8. Delete invoice_line
        $deleted['invoice_line'] = $this->connection->executeStatement(
            "DELETE FROM invoice_line WHERE invoice_id IN ($invoiceSubquery)",
            [$companyId],
        );

        // 9. Null out self-references (parent_document_id)
        $this->connection->executeStatement(
            'UPDATE invoice SET parent_document_id = NULL WHERE company_id = ? AND parent_document_id IS NOT NULL',
            [$companyId],
        );

        // 10. Delete invoices (including soft-deleted ones)
        $deleted['invoice'] = $this->connection->executeStatement(
            'DELETE FROM invoice WHERE company_id = ?',
            [$companyId],
        );

        return $deleted;
    }
}
