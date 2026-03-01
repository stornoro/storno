<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260301151957 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE oauth2_access_token (id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', client_id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', user_id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', organization_id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', token_hash VARCHAR(64) NOT NULL, token_prefix VARCHAR(12) NOT NULL, scopes JSON NOT NULL COMMENT \'(DC2Type:json)\', expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', revoked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_used_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_454D967319EB6921 (client_id), INDEX IDX_454D9673A76ED395 (user_id), INDEX IDX_454D967332C8A3DE (organization_id), INDEX idx_oauth2_access_token_hash (token_hash), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE oauth2_authorization_code (id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', client_id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', user_id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', organization_id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', code_hash VARCHAR(64) NOT NULL, scopes JSON NOT NULL COMMENT \'(DC2Type:json)\', redirect_uri VARCHAR(2048) NOT NULL, code_challenge VARCHAR(128) DEFAULT NULL, code_challenge_method VARCHAR(10) DEFAULT NULL, expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', used_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_509FEF5F19EB6921 (client_id), INDEX IDX_509FEF5FA76ED395 (user_id), INDEX IDX_509FEF5F32C8A3DE (organization_id), INDEX idx_oauth2_auth_code_hash (code_hash), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE oauth2_client (id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', organization_id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', created_by_id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, client_id VARCHAR(68) NOT NULL, client_secret_hash VARCHAR(64) DEFAULT NULL, client_secret_prefix VARCHAR(12) DEFAULT NULL, client_type VARCHAR(20) NOT NULL, redirect_uris JSON NOT NULL COMMENT \'(DC2Type:json)\', scopes JSON NOT NULL COMMENT \'(DC2Type:json)\', website_url VARCHAR(512) DEFAULT NULL, logo_url VARCHAR(512) DEFAULT NULL, is_active TINYINT(1) NOT NULL, revoked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_669FF9C919EB6921 (client_id), INDEX IDX_669FF9C932C8A3DE (organization_id), INDEX IDX_669FF9C9B03A8386 (created_by_id), INDEX idx_oauth2_client_client_id (client_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE oauth2_refresh_token (id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', client_id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', user_id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', organization_id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', access_token_id CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\', token_hash VARCHAR(64) NOT NULL, scopes JSON NOT NULL COMMENT \'(DC2Type:json)\', family VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', revoked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_4DD9073219EB6921 (client_id), INDEX IDX_4DD90732A76ED395 (user_id), INDEX IDX_4DD9073232C8A3DE (organization_id), INDEX IDX_4DD907322CCB2688 (access_token_id), INDEX idx_oauth2_refresh_token_hash (token_hash), INDEX idx_oauth2_refresh_token_family (family), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE oauth2_access_token ADD CONSTRAINT FK_454D967319EB6921 FOREIGN KEY (client_id) REFERENCES oauth2_client (id)');
        $this->addSql('ALTER TABLE oauth2_access_token ADD CONSTRAINT FK_454D9673A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE oauth2_access_token ADD CONSTRAINT FK_454D967332C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id)');
        $this->addSql('ALTER TABLE oauth2_authorization_code ADD CONSTRAINT FK_509FEF5F19EB6921 FOREIGN KEY (client_id) REFERENCES oauth2_client (id)');
        $this->addSql('ALTER TABLE oauth2_authorization_code ADD CONSTRAINT FK_509FEF5FA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE oauth2_authorization_code ADD CONSTRAINT FK_509FEF5F32C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id)');
        $this->addSql('ALTER TABLE oauth2_client ADD CONSTRAINT FK_669FF9C932C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id)');
        $this->addSql('ALTER TABLE oauth2_client ADD CONSTRAINT FK_669FF9C9B03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE oauth2_refresh_token ADD CONSTRAINT FK_4DD9073219EB6921 FOREIGN KEY (client_id) REFERENCES oauth2_client (id)');
        $this->addSql('ALTER TABLE oauth2_refresh_token ADD CONSTRAINT FK_4DD90732A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE oauth2_refresh_token ADD CONSTRAINT FK_4DD9073232C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id)');
        $this->addSql('ALTER TABLE oauth2_refresh_token ADD CONSTRAINT FK_4DD907322CCB2688 FOREIGN KEY (access_token_id) REFERENCES oauth2_access_token (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE oauth2_access_token DROP FOREIGN KEY FK_454D967319EB6921');
        $this->addSql('ALTER TABLE oauth2_access_token DROP FOREIGN KEY FK_454D9673A76ED395');
        $this->addSql('ALTER TABLE oauth2_access_token DROP FOREIGN KEY FK_454D967332C8A3DE');
        $this->addSql('ALTER TABLE oauth2_authorization_code DROP FOREIGN KEY FK_509FEF5F19EB6921');
        $this->addSql('ALTER TABLE oauth2_authorization_code DROP FOREIGN KEY FK_509FEF5FA76ED395');
        $this->addSql('ALTER TABLE oauth2_authorization_code DROP FOREIGN KEY FK_509FEF5F32C8A3DE');
        $this->addSql('ALTER TABLE oauth2_client DROP FOREIGN KEY FK_669FF9C932C8A3DE');
        $this->addSql('ALTER TABLE oauth2_client DROP FOREIGN KEY FK_669FF9C9B03A8386');
        $this->addSql('ALTER TABLE oauth2_refresh_token DROP FOREIGN KEY FK_4DD9073219EB6921');
        $this->addSql('ALTER TABLE oauth2_refresh_token DROP FOREIGN KEY FK_4DD90732A76ED395');
        $this->addSql('ALTER TABLE oauth2_refresh_token DROP FOREIGN KEY FK_4DD9073232C8A3DE');
        $this->addSql('ALTER TABLE oauth2_refresh_token DROP FOREIGN KEY FK_4DD907322CCB2688');
        $this->addSql('DROP TABLE oauth2_access_token');
        $this->addSql('DROP TABLE oauth2_authorization_code');
        $this->addSql('DROP TABLE oauth2_client');
        $this->addSql('DROP TABLE oauth2_refresh_token');
    }
}
