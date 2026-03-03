<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260303065421 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add idNumber and currency fields to client table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client ADD id_number VARCHAR(100) DEFAULT NULL, ADD currency VARCHAR(3) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client DROP id_number, DROP currency');
    }
}
