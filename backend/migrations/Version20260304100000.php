<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260304100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add category column to email_template table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE email_template ADD category VARCHAR(32) NOT NULL DEFAULT 'invoice'");
        $this->addSql('CREATE INDEX idx_emailtemplate_company_category ON email_template (company_id, category)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_emailtemplate_company_category ON email_template');
        $this->addSql('ALTER TABLE email_template DROP category');
    }
}
