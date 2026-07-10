<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260710120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill paid_at/amount_paid on zero- and negative-total invoices (auto-settled — nothing to collect or pay)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "UPDATE invoice
             SET amount_paid = total,
                 paid_at = COALESCE(issue_date, NOW())
             WHERE total <= 0
               AND paid_at IS NULL
               AND deleted_at IS NULL
               AND status NOT IN ('draft', 'cancelled', 'rejected')"
        );
    }

    public function down(Schema $schema): void
    {
        // Irreversible data backfill — settled zero/negative invoices stay settled
    }
}
