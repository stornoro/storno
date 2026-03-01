<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260301150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add default notes and payment terms to PDF template config';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pdf_template_config ADD default_notes LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE pdf_template_config ADD default_payment_terms LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pdf_template_config DROP default_notes');
        $this->addSql('ALTER TABLE pdf_template_config DROP default_payment_terms');
    }
}
