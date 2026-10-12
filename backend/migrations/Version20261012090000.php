<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recurring invoices: a running balance ([[sold]]) reduced on every issuance by an expression';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recurring_invoice ADD balance NUMERIC(14, 2) DEFAULT NULL, ADD balance_step VARCHAR(200) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recurring_invoice DROP balance, DROP balance_step');
    }
}
