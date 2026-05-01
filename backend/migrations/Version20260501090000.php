<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260501090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create app_version_override table for runtime kill-switch on /api/v1/version';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE app_version_override (
            id CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)',
            platform VARCHAR(20) NOT NULL,
            min_override VARCHAR(20) DEFAULT NULL,
            latest_override VARCHAR(20) DEFAULT NULL,
            store_url_override VARCHAR(500) DEFAULT NULL,
            release_notes_url_override VARCHAR(500) DEFAULT NULL,
            message_override JSON DEFAULT NULL,
            updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            updated_by_id CHAR(36) DEFAULT NULL COMMENT '(DC2Type:uuid)',
            UNIQUE INDEX uniq_app_version_override_platform (platform),
            INDEX idx_app_version_override_updated_by (updated_by_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql("ALTER TABLE app_version_override
            ADD CONSTRAINT FK_app_version_override_updated_by
            FOREIGN KEY (updated_by_id) REFERENCES `user` (id) ON DELETE SET NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_version_override DROP FOREIGN KEY FK_app_version_override_updated_by');
        $this->addSql('DROP TABLE app_version_override');
    }
}
