<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Dosare (case files) grouping declarations, SPV requests and SPV messages, with deadlines';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE dosar (id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', company_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', created_by_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\', type VARCHAR(32) NOT NULL, title VARCHAR(255) NOT NULL, subject JSON NOT NULL, status VARCHAR(16) NOT NULL, next_step VARCHAR(500) DEFAULT NULL, deadline_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', deadline_label VARCHAR(120) DEFAULT NULL, deadline_notified JSON NOT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_dosar_company_type (company_id, type), INDEX idx_dosar_company_deadline (company_id, deadline_at), INDEX IDX_DOSAR_CREATED_BY (created_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE dosar ADD CONSTRAINT FK_DOSAR_COMPANY FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE dosar ADD CONSTRAINT FK_DOSAR_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES user (id) ON DELETE SET NULL');
        foreach (['tax_declaration', 'spv_request', 'spv_document'] as $table) {
            $this->addSql(sprintf('ALTER TABLE %s ADD dosar_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'', $table));
            $this->addSql(sprintf('ALTER TABLE %s ADD CONSTRAINT FK_%s_DOSAR FOREIGN KEY (dosar_id) REFERENCES dosar (id) ON DELETE SET NULL', $table, strtoupper($table)));
            $this->addSql(sprintf('CREATE INDEX IDX_%s_DOSAR ON %s (dosar_id)', strtoupper($table), $table));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['tax_declaration', 'spv_request', 'spv_document'] as $table) {
            $this->addSql(sprintf('ALTER TABLE %s DROP FOREIGN KEY FK_%s_DOSAR', $table, strtoupper($table)));
            $this->addSql(sprintf('DROP INDEX IDX_%s_DOSAR ON %s', strtoupper($table), $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP dosar_id', $table));
        }
        $this->addSql('DROP TABLE dosar');
    }
}
