<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recurring invoice lines: a unit price computed from an expression (priceRule "expression")';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recurring_invoice_line ADD price_expression VARCHAR(200) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recurring_invoice_line DROP price_expression');
    }
}
