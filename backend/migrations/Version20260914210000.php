<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Company CAEN code (needed by the VAT return header)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company ADD caen_code VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company DROP caen_code');
    }
}
