<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260301085428 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add scheduled_email_at field to invoice for recurring invoice auto-email';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE invoice ADD scheduled_email_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX idx_invoice_scheduled_email ON invoice (scheduled_email_at, status, deleted_at)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_invoice_scheduled_email ON invoice');
        $this->addSql('ALTER TABLE invoice DROP scheduled_email_at');
    }
}
