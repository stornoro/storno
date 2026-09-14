<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ANAF form versions from DUKIntegrator\'s manifest, watched for changes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE anaf_form_version (id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', form VARCHAR(20) NOT NULL, version_j VARCHAR(40) NOT NULL, version_p VARCHAR(40) NOT NULL, previous_j VARCHAR(40) DEFAULT NULL, previous_p VARCHAR(40) DEFAULT NULL, validator_url VARCHAR(500) DEFAULT NULL, pdf_url VARCHAR(500) DEFAULT NULL, history_url VARCHAR(500) DEFAULT NULL, first_seen_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', changed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', checked_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_anaf_form_version_form (form), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE anaf_form_version');
    }
}
