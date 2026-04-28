<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260428120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace stripe_app_linking_code (one-shot codes) with stripe_app_device_code (RFC 8628 OAuth device flow)';
    }

    public function up(Schema $schema): void
    {
        // Drop a partially-created table from the failed first run of this migration
        $this->addSql('DROP TABLE IF EXISTS stripe_app_device_code');
        $this->addSql('CREATE TABLE stripe_app_device_code (id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', user_id CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\', device_code VARCHAR(64) NOT NULL, user_code VARCHAR(8) NOT NULL, stripe_account_id VARCHAR(255) NOT NULL, status VARCHAR(16) DEFAULT \'pending\' NOT NULL, expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', approved_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_polled_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_STRIPE_APP_DEVICE_CODE_DEVICE_CODE (device_code), UNIQUE INDEX UNIQ_STRIPE_APP_DEVICE_CODE_USER_CODE (user_code), INDEX idx_stripe_app_device_code (device_code), INDEX idx_stripe_app_user_code (user_code), INDEX IDX_STRIPE_APP_DEVICE_CODE_USER (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE stripe_app_device_code ADD CONSTRAINT FK_STRIPE_APP_DEVICE_CODE_USER FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('DROP TABLE IF EXISTS stripe_app_linking_code');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE stripe_app_linking_code (id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', user_id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', code VARCHAR(6) NOT NULL, expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', used_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_stripe_app_linking_code (code), INDEX IDX_STRIPE_APP_LINKING_CODE_USER (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE stripe_app_linking_code ADD CONSTRAINT FK_STRIPE_APP_LINKING_CODE_USER FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('DROP TABLE stripe_app_device_code');
    }
}
