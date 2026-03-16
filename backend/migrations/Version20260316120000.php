<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260316120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add buyer_snapshot JSON column to invoice table for preserving buyer details at creation time';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice ADD buyer_snapshot JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice DROP buyer_snapshot');
    }
}
