<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Company fiscal profile for the fiscal calendar: VAT period, income tax period, employees';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE company ADD vat_period VARCHAR(10) DEFAULT 'monthly' NOT NULL, ADD income_tax_period VARCHAR(10) DEFAULT 'quarterly' NOT NULL, ADD has_employees TINYINT(1) DEFAULT 0 NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company DROP vat_period, DROP income_tax_period, DROP has_employees');
    }
}
