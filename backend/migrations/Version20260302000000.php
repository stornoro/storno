<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260302000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add VIES validation fields to client table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client ADD vies_valid TINYINT(1) DEFAULT NULL, ADD vies_validated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD vies_name VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client DROP vies_valid, DROP vies_validated_at, DROP vies_name');
    }
}
