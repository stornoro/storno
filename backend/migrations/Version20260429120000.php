<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260429120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill paid_at/amount_paid for negative-total invoices issued before auto-settle fix';
    }

    public function up(Schema $schema): void
    {
        // Before the auto-settle generalisation in InvoiceManager::issue(), only
        // parent-linked refunds got paid_at set on issuance. Standalone negative
        // invoices (credit notes without a parent doc) were left with paid_at = NULL
        // and showed as "unpaid" in the UI even though they auto-settle by design.
        $this->addSql(
            "UPDATE invoice
             SET paid_at = NOW(),
                 amount_paid = total
             WHERE total < 0
               AND paid_at IS NULL
               AND deleted_at IS NULL
               AND direction = 'outgoing'
               AND status NOT IN ('draft', 'cancelled')"
        );
    }

    public function down(Schema $schema): void
    {
        // Data backfill — no reliable way to identify which rows we touched.
        $this->throwIrreversibleMigrationException();
    }
}
