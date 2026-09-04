<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260904233000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Company: timestamp of the last successful SPV inbox sync';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company ADD spv_documents_synced_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company DROP spv_documents_synced_at');
    }
}
