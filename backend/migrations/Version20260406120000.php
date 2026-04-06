<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260406120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add sms_enabled column to notification_preference';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification_preference ADD sms_enabled TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification_preference DROP sms_enabled');
    }
}
