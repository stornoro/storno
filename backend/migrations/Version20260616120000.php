<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260616120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add show_vat_in_ron toggle to pdf_template_config (VAT in lei on foreign-currency invoices, Cod Fiscal art. 319 (20) j)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pdf_template_config ADD show_vat_in_ron TINYINT(1) DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pdf_template_config DROP show_vat_in_ron');
    }
}
