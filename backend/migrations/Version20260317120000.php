<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260317120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add pdf_path column to tax_declaration for storing generated unsigned PDFs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tax_declaration ADD pdf_path VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tax_declaration DROP pdf_path');
    }
}
