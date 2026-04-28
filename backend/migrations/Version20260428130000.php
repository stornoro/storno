<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260428130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Scope StripeAppToken + StripeAppDeviceCode to a single company; revoke tokens with no company';
    }

    public function up(Schema $schema): void
    {
        // Tokens issued before scope-fix have no company — revoke; users re-link.
        $this->addSql('DELETE FROM stripe_app_token WHERE company_id IS NULL');

        // MySQL refuses to alter a column type while a FK references it.
        // Look up the actual FK name (Doctrine-generated, env-dependent),
        // drop it, change the column, then restore the FK.
        $tokenFk = $this->connection->fetchOne(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'stripe_app_token'
               AND COLUMN_NAME = 'company_id'
               AND REFERENCED_TABLE_NAME = 'company'
             LIMIT 1"
        );
        if ($tokenFk) {
            $this->addSql(sprintf('ALTER TABLE stripe_app_token DROP FOREIGN KEY %s', $tokenFk));
        }
        $this->addSql('ALTER TABLE stripe_app_token MODIFY company_id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\'');
        if ($tokenFk) {
            $this->addSql(sprintf(
                'ALTER TABLE stripe_app_token ADD CONSTRAINT %s FOREIGN KEY (company_id) REFERENCES company (id)',
                $tokenFk,
            ));
        }

        // device_code: add scope columns + FKs.
        $this->addSql('ALTER TABLE stripe_app_device_code ADD company_id CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\', ADD organization_id CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE stripe_app_device_code ADD CONSTRAINT FK_STRIPE_APP_DEVICE_CODE_COMPANY FOREIGN KEY (company_id) REFERENCES company (id)');
        $this->addSql('ALTER TABLE stripe_app_device_code ADD CONSTRAINT FK_STRIPE_APP_DEVICE_CODE_ORG FOREIGN KEY (organization_id) REFERENCES organization (id)');
        $this->addSql('CREATE INDEX IDX_STRIPE_APP_DEVICE_CODE_COMPANY ON stripe_app_device_code (company_id)');
        $this->addSql('CREATE INDEX IDX_STRIPE_APP_DEVICE_CODE_ORG ON stripe_app_device_code (organization_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stripe_app_device_code DROP FOREIGN KEY FK_STRIPE_APP_DEVICE_CODE_COMPANY');
        $this->addSql('ALTER TABLE stripe_app_device_code DROP FOREIGN KEY FK_STRIPE_APP_DEVICE_CODE_ORG');
        $this->addSql('DROP INDEX IDX_STRIPE_APP_DEVICE_CODE_COMPANY ON stripe_app_device_code');
        $this->addSql('DROP INDEX IDX_STRIPE_APP_DEVICE_CODE_ORG ON stripe_app_device_code');
        $this->addSql('ALTER TABLE stripe_app_device_code DROP company_id, DROP organization_id');

        $tokenFk = $this->connection->fetchOne(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'stripe_app_token'
               AND COLUMN_NAME = 'company_id'
               AND REFERENCED_TABLE_NAME = 'company'
             LIMIT 1"
        );
        if ($tokenFk) {
            $this->addSql(sprintf('ALTER TABLE stripe_app_token DROP FOREIGN KEY %s', $tokenFk));
        }
        $this->addSql('ALTER TABLE stripe_app_token MODIFY company_id CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'');
        if ($tokenFk) {
            $this->addSql(sprintf(
                'ALTER TABLE stripe_app_token ADD CONSTRAINT %s FOREIGN KEY (company_id) REFERENCES company (id)',
                $tokenFk,
            ));
        }
    }
}
