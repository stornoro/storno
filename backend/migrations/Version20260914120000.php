<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Indexes on audit_log for the admin audit log ordering/filters and the per-user activity report';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_audit_log_created_at ON audit_log (created_at)');
        $this->addSql('CREATE INDEX idx_audit_log_user_created ON audit_log (user_id, created_at)');
        $this->addSql('CREATE INDEX idx_audit_log_entity_created ON audit_log (entity_type, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_audit_log_created_at ON audit_log');
        $this->addSql('DROP INDEX idx_audit_log_user_created ON audit_log');
        $this->addSql('DROP INDEX idx_audit_log_entity_created ON audit_log');
    }
}
