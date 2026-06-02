<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260602140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add custom domain columns to white_label_config (Business white-label)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE white_label_config'
            . ' ADD custom_domain VARCHAR(255) DEFAULT NULL,'
            . ' ADD custom_domain_token VARCHAR(64) DEFAULT NULL,'
            . ' ADD custom_domain_verified_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\''
        );
        $this->addSql('CREATE UNIQUE INDEX uniq_white_label_custom_domain ON white_label_config (custom_domain)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_white_label_custom_domain ON white_label_config');
        $this->addSql('ALTER TABLE white_label_config DROP custom_domain, DROP custom_domain_token, DROP custom_domain_verified_at');
    }
}
