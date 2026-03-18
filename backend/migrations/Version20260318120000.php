<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260318120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add import_job_id to client, invoice and payment for import revert tracking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client ADD import_job_id CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE client ADD CONSTRAINT FK_C7440455A89B2E7F FOREIGN KEY (import_job_id) REFERENCES import_job (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_C7440455A89B2E7F ON client (import_job_id)');

        $this->addSql('ALTER TABLE invoice ADD import_job_id CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT FK_90651744A89B2E7F FOREIGN KEY (import_job_id) REFERENCES import_job (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_90651744A89B2E7F ON invoice (import_job_id)');

        $this->addSql('ALTER TABLE payment ADD import_job_id CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840DA89B2E7F FOREIGN KEY (import_job_id) REFERENCES import_job (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_6D28840DA89B2E7F ON payment (import_job_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client DROP FOREIGN KEY FK_C7440455A89B2E7F');
        $this->addSql('DROP INDEX IDX_C7440455A89B2E7F ON client');
        $this->addSql('ALTER TABLE client DROP import_job_id');

        $this->addSql('ALTER TABLE invoice DROP FOREIGN KEY FK_90651744A89B2E7F');
        $this->addSql('DROP INDEX IDX_90651744A89B2E7F ON invoice');
        $this->addSql('ALTER TABLE invoice DROP import_job_id');

        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_6D28840DA89B2E7F');
        $this->addSql('DROP INDEX IDX_6D28840DA89B2E7F ON payment');
        $this->addSql('ALTER TABLE payment DROP import_job_id');
    }
}
