<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260226100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_read_only column to company table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company ADD is_read_only TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company DROP is_read_only');
    }
}
