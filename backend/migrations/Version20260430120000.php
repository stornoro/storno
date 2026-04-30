<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260430120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add quiet-hours flag on user; add push delivery tracking columns + index on notification';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE `user` ADD respect_quiet_hours TINYINT(1) DEFAULT 1 NOT NULL");

        $this->addSql("ALTER TABLE notification
            ADD push_attempted TINYINT(1) DEFAULT 0 NOT NULL,
            ADD push_sent_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            ADD push_error TEXT DEFAULT NULL,
            ADD push_skipped_reason VARCHAR(50) DEFAULT NULL");

        // Used by the cleanup command to find old / per-user-overflow rows quickly.
        $this->addSql('CREATE INDEX idx_notification_user_sent_at ON notification (user_id, sent_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_notification_user_sent_at ON notification');
        $this->addSql('ALTER TABLE notification
            DROP push_attempted,
            DROP push_sent_at,
            DROP push_error,
            DROP push_skipped_reason');
        $this->addSql('ALTER TABLE `user` DROP respect_quiet_hours');
    }
}
