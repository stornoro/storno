<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260425180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add color hex swatch column to product for POS grid presentation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD color VARCHAR(7) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP color');
    }
}
