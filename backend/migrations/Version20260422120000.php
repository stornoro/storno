<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260422120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create user_dashboard_config table for per-user per-company dashboard widget configuration';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE user_dashboard_config (
                id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\',
                user_id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\',
                company_id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\',
                widgets JSON NOT NULL COMMENT \'(DC2Type:json)\',
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX IDX_46A4649AA76ED395 (user_id),
                INDEX IDX_46A4649A979B1AD6 (company_id),
                UNIQUE INDEX uniq_dashboard_user_company (user_id, company_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );
        $this->addSql('ALTER TABLE user_dashboard_config ADD CONSTRAINT FK_46A4649AA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_dashboard_config ADD CONSTRAINT FK_46A4649A979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_dashboard_config DROP FOREIGN KEY FK_46A4649AA76ED395');
        $this->addSql('ALTER TABLE user_dashboard_config DROP FOREIGN KEY FK_46A4649A979B1AD6');
        $this->addSql('DROP TABLE user_dashboard_config');
    }
}
