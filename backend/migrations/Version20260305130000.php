<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260305130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop signature_content column from invoice table (signature is stored on disk via signature_path)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice DROP COLUMN signature_content');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice ADD signature_content TEXT DEFAULT NULL');
    }
}
