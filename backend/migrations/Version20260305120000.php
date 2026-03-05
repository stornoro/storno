<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260305120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create telemetry_event table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE telemetry_event (
            id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\',
            user_id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\',
            company_id CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\',
            event VARCHAR(100) NOT NULL,
            properties JSON NOT NULL,
            platform VARCHAR(20) NOT NULL,
            app_version VARCHAR(20) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_telemetry_company_event (company_id, event, created_at),
            INDEX idx_telemetry_user (user_id, created_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE telemetry_event');
    }
}
