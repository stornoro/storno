<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260407150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create e_invoice_submission table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS e_invoice_submission (
            id CHAR(36) NOT NULL COMMENT \'(DC2Type:app_uuid)\',
            invoice_id CHAR(36) NOT NULL COMMENT \'(DC2Type:app_uuid)\',
            provider VARCHAR(20) NOT NULL,
            external_id VARCHAR(255) DEFAULT NULL,
            status VARCHAR(30) NOT NULL,
            error_message LONGTEXT DEFAULT NULL,
            metadata JSON DEFAULT NULL,
            xml_path VARCHAR(500) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_submission_invoice_provider (invoice_id, provider),
            INDEX idx_submission_status (provider, status),
            PRIMARY KEY(id),
            CONSTRAINT FK_einvoice_submission_invoice FOREIGN KEY (invoice_id) REFERENCES invoice (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS e_invoice_submission');
    }
}
