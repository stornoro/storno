<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260915100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Imports: summary on the import job; receipts remember their cash register serial and the import that created them';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_job ADD summary JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE receipt ADD device_serial VARCHAR(30) DEFAULT NULL, ADD import_job_id CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE receipt ADD CONSTRAINT FK_RECEIPT_IMPORT_JOB FOREIGN KEY (import_job_id) REFERENCES import_job (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_RECEIPT_IMPORT_JOB ON receipt (import_job_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE receipt DROP FOREIGN KEY FK_RECEIPT_IMPORT_JOB');
        $this->addSql('DROP INDEX IDX_RECEIPT_IMPORT_JOB ON receipt');
        $this->addSql('ALTER TABLE receipt DROP device_serial, DROP import_job_id');
        $this->addSql('ALTER TABLE import_job DROP summary');
    }
}
