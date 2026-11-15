<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Supplier: the import job that created it (supplier import from file), and a longer source';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE supplier ADD import_job_id CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\', CHANGE source source VARCHAR(40) NOT NULL');
        $this->addSql('ALTER TABLE supplier ADD CONSTRAINT FK_9B2A6C7E77D1F4B1 FOREIGN KEY (import_job_id) REFERENCES import_job (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_9B2A6C7E77D1F4B1 ON supplier (import_job_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE supplier DROP FOREIGN KEY FK_9B2A6C7E77D1F4B1');
        $this->addSql('DROP INDEX IDX_9B2A6C7E77D1F4B1 ON supplier');
        $this->addSql('ALTER TABLE supplier DROP import_job_id, CHANGE source source VARCHAR(20) NOT NULL');
    }
}
