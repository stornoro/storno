<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260226200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add enabled_modules JSON field to company table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company ADD enabled_modules JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company DROP enabled_modules');
    }
}
