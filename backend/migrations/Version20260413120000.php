<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260413120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add skip_etransport flag to delivery_note table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_note ADD skip_etransport TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_note DROP COLUMN skip_etransport');
    }
}
