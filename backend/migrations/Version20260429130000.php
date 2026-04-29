<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260429130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill paid_at for negative invoices missed by Version20260429120000 (incoming + direction IS NULL)';
    }

    public function up(Schema $schema): void
    {
        // Version20260429120000 only touched direction='outgoing'. Incoming credit
        // notes and legacy rows with direction IS NULL were left unpaid even though
        // negatives auto-settle by design. Frontend "unpaid" badge ignores direction.
        $this->addSql(
            "UPDATE invoice
             SET paid_at = NOW(),
                 amount_paid = total
             WHERE total < 0
               AND paid_at IS NULL
               AND deleted_at IS NULL
               AND status NOT IN ('draft', 'cancelled')"
        );
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException();
    }
}
