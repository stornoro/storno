<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260510090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add lifecycle-email indexes on email_log (category+sent_at, sent_by_id+sent_at)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_emaillog_category_sent ON email_log (category, sent_at)');
        $this->addSql('CREATE INDEX idx_emaillog_sent_by_sent ON email_log (sent_by_id, sent_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_emaillog_category_sent ON email_log');
        $this->addSql('DROP INDEX idx_emaillog_sent_by_sent ON email_log');
    }
}
