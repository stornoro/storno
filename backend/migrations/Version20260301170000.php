<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260301170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add label_overrides JSON column to pdf_template_config';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pdf_template_config ADD label_overrides JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pdf_template_config DROP label_overrides');
    }
}
