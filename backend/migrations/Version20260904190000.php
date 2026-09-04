<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260904190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SPV inbox archive: spv_document table (every ANAF SPV message per company, classified, with archived PDF)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE spv_document (
            id CHAR(36) NOT NULL,
            company_id CHAR(36) NOT NULL,
            anaf_message_id VARCHAR(64) NOT NULL,
            message_type VARCHAR(255) NOT NULL,
            category VARCHAR(32) NOT NULL,
            severity VARCHAR(16) NOT NULL,
            cif VARCHAR(20) DEFAULT NULL,
            details LONGTEXT DEFAULT NULL,
            id_solicitare VARCHAR(64) DEFAULT NULL,
            anaf_created_at DATETIME DEFAULT NULL,
            pdf_path VARCHAR(500) DEFAULT NULL,
            file_name VARCHAR(255) DEFAULT NULL,
            file_size INT DEFAULT NULL,
            downloaded_at DATETIME DEFAULT NULL,
            download_error LONGTEXT DEFAULT NULL,
            read_at DATETIME DEFAULT NULL,
            notified_at DATETIME DEFAULT NULL,
            purged_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE INDEX uniq_spv_doc_company_anaf (company_id, anaf_message_id),
            INDEX idx_spv_doc_company_created (company_id, anaf_created_at),
            INDEX idx_spv_doc_company_category (company_id, category),
            INDEX idx_spv_doc_company_severity (company_id, severity),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE spv_document ADD CONSTRAINT FK_SPV_DOC_COMPANY FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE spv_document');
    }
}
