<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add processed_webhook_event table for Stripe webhook idempotency.
 */
final class Version20260226000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create processed_webhook_event table for Stripe webhook idempotency tracking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE processed_webhook_event (id INT AUTO_INCREMENT NOT NULL, event_id VARCHAR(255) NOT NULL, event_type VARCHAR(255) NOT NULL, processed_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_PROCESSED_WEBHOOK_EVENT_ID (event_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE processed_webhook_event');
    }
}
