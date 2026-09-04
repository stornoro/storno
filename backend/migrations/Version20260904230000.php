<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260904230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SPV requests (solicitari prin SPVWS2 cerere): spv_request table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE spv_request (
            id CHAR(36) NOT NULL,
            company_id CHAR(36) NOT NULL,
            request_type VARCHAR(255) NOT NULL,
            params JSON NOT NULL,
            anaf_request_id VARCHAR(64) DEFAULT NULL,
            title VARCHAR(255) DEFAULT NULL,
            status VARCHAR(16) NOT NULL,
            error_message LONGTEXT DEFAULT NULL,
            requested_by_id CHAR(36) DEFAULT NULL,
            answer_document_id CHAR(36) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            answered_at DATETIME DEFAULT NULL,
            INDEX idx_spv_req_company_created (company_id, created_at),
            INDEX idx_spv_req_company_anaf (company_id, anaf_request_id),
            INDEX IDX_SPV_REQ_USER (requested_by_id),
            INDEX IDX_SPV_REQ_DOC (answer_document_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE spv_request ADD CONSTRAINT FK_SPV_REQ_COMPANY FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE spv_request ADD CONSTRAINT FK_SPV_REQ_USER FOREIGN KEY (requested_by_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE spv_request ADD CONSTRAINT FK_SPV_REQ_DOC FOREIGN KEY (answer_document_id) REFERENCES spv_document (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE spv_request');
    }
}
