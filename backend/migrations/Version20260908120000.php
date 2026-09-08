<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Files kept in a dosar (contract scan, addendum, termination document, signed statement)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE dosar_file (id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', dosar_id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', uploaded_by_id CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\', kind VARCHAR(32) NOT NULL, name VARCHAR(255) NOT NULL, path VARCHAR(500) NOT NULL, size INT NOT NULL, mime VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_dosar_file_dosar (dosar_id), INDEX IDX_DOSAR_FILE_UPLOADED_BY (uploaded_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE dosar_file ADD CONSTRAINT FK_DOSAR_FILE_DOSAR FOREIGN KEY (dosar_id) REFERENCES dosar (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE dosar_file ADD CONSTRAINT FK_DOSAR_FILE_UPLOADED_BY FOREIGN KEY (uploaded_by_id) REFERENCES user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE dosar_file');
    }
}
