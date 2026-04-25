<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260425170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add idempotency_key to receipt for safe POS retries (offline queue, ambiguous timeouts)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE receipt ADD idempotency_key VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_RECEIPT_IDEMPOTENCY_KEY ON receipt (idempotency_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_RECEIPT_IDEMPOTENCY_KEY ON receipt');
        $this->addSql('ALTER TABLE receipt DROP idempotency_key');
    }
}
