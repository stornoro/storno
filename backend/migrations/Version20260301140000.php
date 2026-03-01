<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260301140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add bank account invoice display fields and PDF template bank display config';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bank_account ADD show_on_invoice TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE pdf_template_config ADD bank_display_section VARCHAR(20) DEFAULT \'both\' NOT NULL');
        $this->addSql('ALTER TABLE pdf_template_config ADD bank_display_mode VARCHAR(20) DEFAULT \'stacked\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bank_account DROP show_on_invoice');
        $this->addSql('ALTER TABLE pdf_template_config DROP bank_display_section');
        $this->addSql('ALTER TABLE pdf_template_config DROP bank_display_mode');
    }
}
