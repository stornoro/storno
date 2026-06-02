<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260602120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add white_label_config table (per-organization branding, Business plan)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE white_label_config ('
            . ' id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\','
            . ' organization_id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\','
            . ' created_by_id CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\','
            . ' updated_by_id CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\','
            . ' enabled TINYINT(1) NOT NULL,'
            . ' app_name VARCHAR(100) DEFAULT NULL,'
            . ' logo_path VARCHAR(255) DEFAULT NULL,'
            . ' primary_color VARCHAR(7) DEFAULT NULL,'
            . ' remove_branding TINYINT(1) NOT NULL,'
            . ' created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\','
            . ' updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\','
            . ' UNIQUE INDEX UNIQ_WHITE_LABEL_CONFIG_ORG (organization_id),'
            . ' INDEX IDX_WHITE_LABEL_CONFIG_CREATED_BY (created_by_id),'
            . ' INDEX IDX_WHITE_LABEL_CONFIG_UPDATED_BY (updated_by_id),'
            . ' PRIMARY KEY(id)'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );
        $this->addSql('ALTER TABLE white_label_config ADD CONSTRAINT FK_WHITE_LABEL_CONFIG_ORG FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE white_label_config ADD CONSTRAINT FK_WHITE_LABEL_CONFIG_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE white_label_config ADD CONSTRAINT FK_WHITE_LABEL_CONFIG_UPDATED_BY FOREIGN KEY (updated_by_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE white_label_config');
    }
}
