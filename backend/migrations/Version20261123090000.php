<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261123090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Indexes for list sorts, overdue filters, notification inbox and age-based retention purges';
    }

    public function isTransactional(): bool
    {
        // DDL commits implicitly on MySQL, so a wrapping transaction only adds noise.
        return false;
    }

    public function up(Schema $schema): void
    {
        // Every statement is an in-place, non-locking build on InnoDB, so writes continue during the migration.
        $this->addSql('ALTER TABLE payment ADD INDEX idx_payment_company_date (company_id, payment_date), ALGORITHM=INPLACE, LOCK=NONE');
        $this->addSql('ALTER TABLE invoice ADD INDEX idx_invoice_company_due (company_id, deleted_at, due_date), ALGORITHM=INPLACE, LOCK=NONE');
        // Widened with created_at so countThisMonth is answered from the index. Drop and add in one ALTER.
        $this->addSql('ALTER TABLE invoice DROP INDEX idx_invoice_company_direction, ADD INDEX idx_invoice_company_direction (company_id, direction, deleted_at, created_at), ALGORITHM=INPLACE, LOCK=NONE');
        $this->addSql('ALTER TABLE notification ADD INDEX idx_notification_user_read_sent (user_id, is_read, sent_at), ALGORITHM=INPLACE, LOCK=NONE');
        $this->addSql('ALTER TABLE client ADD INDEX idx_client_company_created (company_id, deleted_at, created_at), ALGORITHM=INPLACE, LOCK=NONE');
        $this->addSql('ALTER TABLE supplier ADD INDEX idx_supplier_company_created (company_id, deleted_at, created_at), ALGORITHM=INPLACE, LOCK=NONE');
        // Age-only indexes for retention purges that delete across all tenants.
        $this->addSql('ALTER TABLE webhook_delivery ADD INDEX idx_webhook_delivery_triggered (triggered_at), ALGORITHM=INPLACE, LOCK=NONE');
        $this->addSql('ALTER TABLE email_log ADD INDEX idx_emaillog_sent (sent_at), ALGORITHM=INPLACE, LOCK=NONE');
        $this->addSql('ALTER TABLE telemetry_event ADD INDEX idx_telemetry_created (created_at), ALGORITHM=INPLACE, LOCK=NONE');
        $this->addSql('ALTER TABLE login_history ADD INDEX idx_login_history_date (`date`), ALGORITHM=INPLACE, LOCK=NONE');
        $this->addSql('ALTER TABLE document_event ADD INDEX idx_document_event_created (created_at), ALGORITHM=INPLACE, LOCK=NONE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_event DROP INDEX idx_document_event_created, ALGORITHM=INPLACE, LOCK=NONE');
        $this->addSql('ALTER TABLE login_history DROP INDEX idx_login_history_date, ALGORITHM=INPLACE, LOCK=NONE');
        $this->addSql('ALTER TABLE telemetry_event DROP INDEX idx_telemetry_created, ALGORITHM=INPLACE, LOCK=NONE');
        $this->addSql('ALTER TABLE email_log DROP INDEX idx_emaillog_sent, ALGORITHM=INPLACE, LOCK=NONE');
        $this->addSql('ALTER TABLE webhook_delivery DROP INDEX idx_webhook_delivery_triggered, ALGORITHM=INPLACE, LOCK=NONE');
        $this->addSql('ALTER TABLE supplier DROP INDEX idx_supplier_company_created, ALGORITHM=INPLACE, LOCK=NONE');
        $this->addSql('ALTER TABLE client DROP INDEX idx_client_company_created, ALGORITHM=INPLACE, LOCK=NONE');
        $this->addSql('ALTER TABLE notification DROP INDEX idx_notification_user_read_sent, ALGORITHM=INPLACE, LOCK=NONE');
        $this->addSql('ALTER TABLE invoice DROP INDEX idx_invoice_company_direction, ADD INDEX idx_invoice_company_direction (company_id, direction, deleted_at), ALGORITHM=INPLACE, LOCK=NONE');
        $this->addSql('ALTER TABLE invoice DROP INDEX idx_invoice_company_due, ALGORITHM=INPLACE, LOCK=NONE');
        $this->addSql('ALTER TABLE payment DROP INDEX idx_payment_company_date, ALGORITHM=INPLACE, LOCK=NONE');
    }
}
