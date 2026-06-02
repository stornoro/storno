<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260602130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add mailer_config table (per-organization custom email sender, Business plan)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE mailer_config ('
            . ' id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\','
            . ' organization_id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\','
            . ' created_by_id CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\','
            . ' updated_by_id CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\','
            . ' enabled TINYINT(1) NOT NULL,'
            . ' host VARCHAR(255) NOT NULL,'
            . ' port INT NOT NULL,'
            . ' encryption VARCHAR(10) NOT NULL,'
            . ' username VARCHAR(255) DEFAULT NULL,'
            . ' encrypted_credentials LONGTEXT NOT NULL,'
            . ' from_address VARCHAR(255) NOT NULL,'
            . ' from_name VARCHAR(255) DEFAULT NULL,'
            . ' last_tested_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\','
            . ' created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\','
            . ' updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\','
            . ' UNIQUE INDEX uniq_mailer_config_organization (organization_id),'
            . ' INDEX IDX_MAILER_CONFIG_CREATED_BY (created_by_id),'
            . ' INDEX IDX_MAILER_CONFIG_UPDATED_BY (updated_by_id),'
            . ' PRIMARY KEY(id)'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );
        $this->addSql('ALTER TABLE mailer_config ADD CONSTRAINT FK_MAILER_CONFIG_ORG FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mailer_config ADD CONSTRAINT FK_MAILER_CONFIG_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE mailer_config ADD CONSTRAINT FK_MAILER_CONFIG_UPDATED_BY FOREIGN KEY (updated_by_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mailer_config');
    }
}
