<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260904234000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SPV documents: plain-language summary columns (ro + en) (backfilled by app:spv:resummarize)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spv_document ADD summary LONGTEXT DEFAULT NULL, ADD summary_en LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spv_document DROP summary, DROP summary_en');
    }
}
