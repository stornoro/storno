<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260425210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add product.sgr_amount for Romanian SGR (Sistem Garantie-Returnare) deposit';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD sgr_amount NUMERIC(5, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP sgr_amount');
    }
}
